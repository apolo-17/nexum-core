<?php

namespace App\Services\Registration;

use App\Models\Document;
use App\Services\Document\PdfCompressionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Reads a protocolized incorporation deed (acta protocolizada) with Claude vision and extracts,
 * as structured data, who is in it: the Chinese shareholders/managers and — the part that matters
 * for SAT appointments — the Mexican fiscal attorneys (apoderados fiscales) with their RFC.
 *
 * The acta is the final, notarized source of truth, so a company that only has its acta uploaded
 * can still have its legal representatives recovered from it. The service also VERIFIES the file
 * really is an acta protocolizada, so a mis-uploaded document is caught instead of trusted.
 *
 * Actas are 30-37 MB scans; the Anthropic request has a size ceiling, so the PDF is Ghostscript-
 * compressed before it is sent (reusing the same compressor the China relay uses).
 */
class ActaExtractionService
{
    private const ANTHROPIC_API_URL = 'https://api.anthropic.com/v1/messages';

    private const ANTHROPIC_VERSION = '2023-06-01';

    /** Target size for the PDF sent to the API (well under the request ceiling once base64'd). */
    private const SEND_TARGET_BYTES = 8 * 1024 * 1024;

    public function __construct(private readonly PdfCompressionService $compressor) {}

    /**
     * Extract the parties from an acta protocolizada document.
     *
     * @param  Document  $acta  The acta protocolizada document (PDF with a stored file).
     * @return array{
     *     is_acta_protocolizada: bool,
     *     confidence: float,
     *     reason_if_not: ?string,
     *     denominacion: ?string,
     *     escritura: array<string, mixed>,
     *     socios: list<array<string, mixed>>,
     *     apoderados_fiscales: list<array{nombre: string, rfc: ?string}>
     * } The parsed extraction (or a ['_raw' => ...] shape if the model did not return JSON).
     *
     * @throws RuntimeException When the file is missing or the API call fails.
     */
    public function extract(Document $acta): array
    {
        $base64 = $this->preparePdfBase64($acta);
        $response = $this->callClaude($base64, $this->prompt());

        return $response;
    }

    /**
     * Download the acta (preferring an existing compressed derivative), shrink it to a size the
     * API accepts, and return it base64-encoded.
     */
    private function preparePdfBase64(Document $acta): string
    {
        $disk = Storage::disk();
        $source = filled($acta->relay_storage_path) && $disk->exists((string) $acta->relay_storage_path)
            ? (string) $acta->relay_storage_path
            : (string) $acta->storage_path;

        if (blank($source) || ! $disk->exists($source)) {
            throw new RuntimeException("Acta document {$acta->id} has no stored file to read.");
        }

        // Small enough already — send as-is.
        if ((int) $disk->size($source) <= self::SEND_TARGET_BYTES) {
            return base64_encode((string) $disk->get($source));
        }

        $localIn = tempnam(sys_get_temp_dir(), 'acta_in_');
        $localOut = tempnam(sys_get_temp_dir(), 'acta_out_');

        try {
            $stream = $disk->readStream($source);
            $handle = fopen($localIn, 'wb');
            stream_copy_to_stream($stream, $handle);
            fclose($handle);
            if (is_resource($stream)) {
                fclose($stream);
            }

            $this->compressor->compress($localIn, $localOut, self::SEND_TARGET_BYTES);

            return base64_encode((string) file_get_contents($localOut));
        } finally {
            @unlink($localIn);
            @unlink($localOut);
        }
    }

    /**
     * Ask Claude to read the acta and return the structured parties as JSON.
     */
    private function callClaude(string $base64Pdf, string $prompt): array
    {
        $apiKey = (string) config('services.anthropic.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        $response = Http::timeout(120)->withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
            'content-type' => 'application/json',
        ])->post(self::ANTHROPIC_API_URL, [
            'model' => (string) config('services.anthropic.model', 'claude-opus-4-6'),
            'max_tokens' => 3000,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'document',
                        'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $base64Pdf],
                    ],
                    ['type' => 'text', 'text' => $prompt],
                ],
            ]],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException("Claude API error {$response->status()}: ".substr($response->body(), 0, 400));
        }

        $rawText = (string) ($response->json('content.0.text') ?? '');
        $jsonText = preg_replace('/^```(?:json)?\s*/m', '', $rawText);
        $jsonText = preg_replace('/\s*```$/m', '', $jsonText ?? $rawText);
        $parsed = json_decode(trim((string) ($jsonText ?? $rawText)), associative: true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('ActaExtractionService: model did not return JSON.', ['raw' => substr($rawText, 0, 500)]);

            return ['_raw' => $rawText];
        }

        return $parsed;
    }

    /**
     * The extraction instruction. Spanish, because the source document and downstream users are.
     */
    private function prompt(): string
    {
        return <<<'PROMPT'
Eres un asistente legal experto en actas constitutivas mexicanas (escrituras notariadas).
Se te entrega un documento PDF. Analízalo y responde ÚNICAMENTE con un objeto JSON válido (sin texto
fuera del JSON, sin ```), con EXACTAMENTE esta estructura:

{
  "is_acta_protocolizada": true|false,   // true solo si es una ESCRITURA PÚBLICA PROTOCOLIZADA ante notario (acta constitutiva notariada), no un borrador ni un render sin protocolizar
  "confidence": 0.0-1.0,                  // qué tan seguro estás de que es un acta protocolizada
  "reason_if_not": "..."|null,            // si is_acta_protocolizada es false, explica brevemente qué es en su lugar
  "denominacion": "..."|null,             // denominación/razón social de la empresa constituida
  "escritura": { "numero": "..."|null, "notario": "..."|null, "fecha": "..."|null },
  "socios": [                             // accionistas/socios (normalmente extranjeros) y su cargo en la administración
    { "nombre": "...", "nacionalidad": "..."|null, "identificacion": "..."|null, "participacion": "..."|null, "cargo": "Presidente|Secretario|Socio|Gerente|..."|null }
  ],
  "apoderados_fiscales": [                // personas (normalmente mexicanas) a quienes el acta OTORGA PODER para trámites fiscales/e.firma ante el SAT; EXTRAE su RFC textual
    { "nombre": "...", "rfc": "..."|null }
  ]
}

Reglas:
- El RFC de persona física mexicana tiene 13 caracteres (4 letras + 6 dígitos + 3 alfanuméricos). Extráelo EXACTO como aparece; no lo inventes. Si no hay RFC legible para un apoderado, pon null.
- "apoderados_fiscales" son quienes reciben el PODER/mandato (cláusula o numeral de poderes), NO los socios ni los gerentes chinos. Es la lista más importante.
- Si el documento NO es un acta protocolizada, pon is_acta_protocolizada=false y deja las listas vacías.
- Responde solo el JSON.
PROMPT;
    }
}
