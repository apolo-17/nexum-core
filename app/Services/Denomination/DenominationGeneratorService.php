<?php

declare(strict_types=1);

namespace App\Services\Denomination;

use Illuminate\Support\Facades\Http;

/**
 * Generates candidate company denominations (razones sociales) with Claude.
 *
 * Used to pre-fill a proactive pool of names that get reserved with the SE ahead
 * of any expedient, so the team always has approved denominations in stock. The
 * service only produces name strings — persistence, FIEL assignment and SE
 * submission are handled by the caller / the existing MUA flow.
 */
class DenominationGeneratorService
{
    /**
     * Anthropic Messages API endpoint.
     */
    private const ANTHROPIC_API_URL = 'https://api.anthropic.com/v1/messages';

    /**
     * Anthropic API version header.
     */
    private const ANTHROPIC_VERSION = '2023-06-01';

    /**
     * Model used for name generation — fast and capable enough for this task.
     */
    private const CLAUDE_MODEL = 'claude-sonnet-4-6';

    /**
     * Generate a list of candidate denominations.
     *
     * @param  int  $quantity  How many names to generate (clamped 1–20).
     * @return list<string> Distinct, upper-cased candidate names.
     *
     * @throws \RuntimeException When the API key is missing or the API call fails.
     */
    public function generate(int $quantity = 10): array
    {
        $quantity = max(1, min(20, $quantity));

        $apiKey = config('services.anthropic.api_key');

        if (blank($apiKey)) {
            throw new \RuntimeException('ANTHROPIC_API_KEY is not configured.');
        }

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
            'content-type' => 'application/json',
        ])->post(self::ANTHROPIC_API_URL, [
            'model' => self::CLAUDE_MODEL,
            'max_tokens' => 1024,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->prompt($quantity),
                ],
            ],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Claude API error {$response->status()}: {$response->body()}"
            );
        }

        $rawText = $response->json('content.0.text', '');

        return $this->parseNames($rawText, $quantity);
    }

    /**
     * Build the generation prompt.
     *
     * @param  int  $quantity  Number of names requested.
     */
    private function prompt(int $quantity): string
    {
        return <<<PROMPT
            Genera exactamente {$quantity} propuestas de denominación social (razón social)
            para una empresa nueva en México. Reglas:
            - Nombres originales, sobrios y profesionales, evocando consultoría, comercio o servicios.
            - En español. Sin el tipo de sociedad al final (no agregues "S.A.", "S. de R.L.", etc.).
            - Entre 2 y 4 palabras. Sin comillas, sin numeración.
            - Evita marcas conocidas y términos restringidos (México, Nacional, Banco, etc.).
            Devuelve ÚNICAMENTE un arreglo JSON de cadenas, por ejemplo:
            ["NOMBRE UNO", "NOMBRE DOS"]
            Nada de texto adicional.
            PROMPT;
    }

    /**
     * Parse the model output into a clean, distinct, upper-cased list of names.
     *
     * Tolerates the model wrapping the JSON in prose or code fences by extracting
     * the first JSON array found; falls back to line splitting if no array parses.
     *
     * @param  string  $rawText  Raw text returned by the model.
     * @param  int  $quantity  Requested count, used to cap the result.
     * @return list<string>
     */
    private function parseNames(string $rawText, int $quantity): array
    {
        $names = [];

        if (preg_match('/\[.*\]/s', $rawText, $matches) === 1) {
            $decoded = json_decode($matches[0], true);

            if (is_array($decoded)) {
                $names = $decoded;
            }
        }

        if ($names === []) {
            $names = preg_split('/\r?\n/', trim($rawText)) ?: [];
        }

        $clean = [];

        foreach ($names as $name) {
            $name = trim((string) $name, " \t\n\r\0\x0B\"'-•·");

            if ($name !== '') {
                $clean[mb_strtoupper($name)] = true;
            }
        }

        return array_slice(array_keys($clean), 0, $quantity);
    }
}
