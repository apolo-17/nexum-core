<?php

declare(strict_types=1);

namespace App\Services\Singapur;

use App\Models\Document;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Uses Claude to turn raw relay errors and China's rejection reasons into a short, clear
 * Spanish message for the admin alert. Best-effort: if the API key is missing or Claude
 * fails, it falls back to a plain built-in message so an alert always has readable text.
 */
class RelayMessageAi
{
    private const ANTHROPIC_API_URL = 'https://api.anthropic.com/v1/messages';

    private const ANTHROPIC_VERSION = '2023-06-01';

    private const CLAUDE_MODEL = 'claude-opus-4-6';

    private const MAX_TOKENS = 400;

    /**
     * Friendly Spanish explanation of a failed delivery (from the raw exception message).
     */
    public function explainFailure(Document $document, string $rawError): string
    {
        $empresa = $document->registration?->primaryLegalName?->name ?? 'la empresa';
        $tipo = $document->type instanceof \BackedEnum ? $document->type->value : (string) $document->type;

        $fallback = "No se pudo enviar el documento ({$tipo}) de {$empresa} a China. Detalle técnico: "
            .mb_substr($rawError, 0, 200);

        $message = $this->ask(
            'Eres un asistente que explica, en español claro y breve (máximo 2 frases), por qué falló '
            .'el envío de un documento a un sistema externo. No uses jerga técnica; di qué pasó y, si aplica, '
            .'qué conviene hacer. No inventes detalles que no estén en el error.',
            "Documento: {$tipo}\nEmpresa: {$empresa}\nError técnico: {$rawError}\n\nExplica el fallo:",
        );

        return $message ?? $fallback;
    }

    /**
     * Translate/clean up China's rejection reason into Spanish for the operator.
     */
    public function translateRejection(Document $document, string $reason): string
    {
        $empresa = $document->registration?->primaryLegalName?->name ?? 'la empresa';
        $tipo = $document->type instanceof \BackedEnum ? $document->type->value : (string) $document->type;

        $message = $this->ask(
            'Traduce al español, de forma clara y fiel, el motivo por el que China rechazó un documento. '
            .'Si ya está en español, solo límpialo. Devuelve SOLO el motivo traducido, sin preámbulos.',
            "Documento: {$tipo} de {$empresa}\nMotivo (chino o inglés): {$reason}\n\nMotivo en español:",
        );

        return $message ?? $reason;
    }

    /**
     * Single-shot Claude text call. Returns null on any failure (missing key, API error).
     */
    private function ask(string $system, string $user): ?string
    {
        $apiKey = config('services.anthropic.api_key');

        if (blank($apiKey)) {
            return null;
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => self::ANTHROPIC_VERSION,
                    'content-type' => 'application/json',
                ])
                ->post(self::ANTHROPIC_API_URL, [
                    'model' => self::CLAUDE_MODEL,
                    'max_tokens' => self::MAX_TOKENS,
                    'system' => $system,
                    'messages' => [
                        ['role' => 'user', 'content' => $user],
                    ],
                ]);

            if ($response->failed()) {
                Log::warning('RelayMessageAi: Claude returned an error.', ['status' => $response->status()]);

                return null;
            }

            $text = trim((string) ($response->json('content.0.text') ?? ''));

            return $text !== '' ? $text : null;
        } catch (\Throwable $exception) {
            Log::warning('RelayMessageAi: Claude call failed.', ['error' => $exception->getMessage()]);

            return null;
        }
    }
}
