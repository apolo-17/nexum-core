<?php

namespace App\Services\Reporting;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Writes the opening narrative of the daily digest with Claude.
 *
 * The model receives ONLY the figures DailyDigestService already computed and is
 * asked to turn them into a short briefing: a greeting, what the report covers,
 * a reading of where the portfolio stands, and the two or three things worth
 * doing today. It never calculates anything — every number in the email comes
 * from the database, and the prompt forbids introducing any other figure.
 *
 * Failure is never fatal: if the API is down, misconfigured or slow, narrate()
 * returns null and the email falls back to its static introduction. A daily
 * report that cannot be sent is worse than one without a narrative.
 */
class DailyDigestNarrator
{
    /**
     * Anthropic API endpoint for the Messages API.
     */
    private const ANTHROPIC_API_URL = 'https://api.anthropic.com/v1/messages';

    /**
     * API version header required by Anthropic.
     */
    private const ANTHROPIC_VERSION = '2023-06-01';

    /**
     * Claude model used to write the briefing.
     */
    private const CLAUDE_MODEL = 'claude-opus-5';

    /**
     * Upper bound for the response. Generous because the model thinks before it
     * writes, and a truncated briefing would reach the recipients as such.
     */
    private const MAX_TOKENS = 4000;

    /**
     * Seconds to wait for the API before giving up and sending the email without
     * a narrative.
     */
    private const TIMEOUT_SECONDS = 60;

    /**
     * Turn the digest payload into a short written briefing.
     *
     * @param  array<string, mixed>  $digest  Payload produced by DailyDigestService::build().
     * @return array{greeting: string, summary: string, priorities: array<int, string>}|null
     *                                                                                       The briefing, or null when the API could not be reached or understood.
     */
    public function narrate(array $digest): ?array
    {
        $apiKey = config('services.anthropic.api_key');

        if (blank($apiKey)) {
            Log::warning('DailyDigestNarrator: ANTHROPIC_API_KEY is not configured; sending the digest without a narrative.');

            return null;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => self::ANTHROPIC_VERSION,
                'content-type' => 'application/json',
            ])
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(self::ANTHROPIC_API_URL, [
                    'model' => self::CLAUDE_MODEL,
                    'max_tokens' => self::MAX_TOKENS,
                    'system' => $this->systemPrompt(),
                    'output_config' => [
                        // A summarisation task over pre-computed data: low effort keeps
                        // latency and cost down without hurting the writing.
                        'effort' => 'low',
                        'format' => [
                            'type' => 'json_schema',
                            'schema' => $this->schema(),
                        ],
                    ],
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $this->userPrompt($digest),
                        ],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning('DailyDigestNarrator: the Claude request failed; sending the digest without a narrative.', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('DailyDigestNarrator: Claude returned an error; sending the digest without a narrative.', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $this->parse($response->json());
    }

    // -------------------------------------------------------------------------
    // Private — prompt construction
    // -------------------------------------------------------------------------

    /**
     * Instructions that define the voice and the hard limits of the briefing.
     */
    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            Eres el analista de operaciones de Nexum Core, el sistema que la notaría usa para
            constituir empresas mexicanas para clientes chinos. Cada mañana escribes la
            introducción del reporte diario que reciben por correo el super_admin y el equipo
            de notaría.

            Escribes en español de México, en tono profesional y directo, como un colega que
            ya revisó los números y dice qué importa hoy. Nada de emojis, nada de markdown,
            nada de viñetas dentro del texto.

            Reglas que no puedes romper:
            - Usa ÚNICAMENTE las cifras del JSON que recibes. No calcules, no estimes, no
              redondees y no inventes ningún número, nombre de empresa, código de expediente
              ni fecha que no venga en el JSON.
            - No repitas la lista completa de expedientes: el correo ya trae las tablas.
              Tu trabajo es interpretar, no enumerar.
            - Si algo lleva mucho tiempo detenido, di por qué importa (quién está esperando:
              la Secretaría de Economía, el SAT, los socios en China, o el propio equipo).
            - Si no hay nada atrasado, dilo con claridad y en una sola frase. No inventes
              urgencia que no existe.
            - Las prioridades deben ser acciones concretas sobre expedientes reales del JSON,
              no consejos genéricos.

            Vocabulario del negocio: "expediente" es el proceso completo de constitución de una
            empresa; "denominación" es el nombre social que autoriza la Secretaría de Economía
            (SE); "e.firma" y "RFC" son trámites ante el SAT; "China" es el relay que envía los
            expedientes nuevos.
            PROMPT;
    }

    /**
     * Build the user turn: the facts as JSON plus what to write about them.
     *
     * @param  array<string, mixed>  $digest  Digest payload.
     * @return string The user message.
     */
    private function userPrompt(array $digest): string
    {
        $facts = json_encode(
            $this->facts($digest),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return <<<PROMPT
            Estos son los datos del corte de hoy:

            {$facts}

            Escribe la introducción del reporte con tres partes:

            1. greeting: un saludo de una sola frase que empiece con "Buenos días" y diga qué
               es este correo y a qué corte corresponde.
            2. summary: de dos a cuatro frases que expliquen dónde está la cartera hoy: cuánto
               hay activo, qué se atoró y dónde, y qué se movió desde el corte anterior.
            3. priorities: de dos a tres acciones concretas para hoy, cada una en una frase,
               referida a expedientes que aparezcan en los datos.
            PROMPT;
    }

    /**
     * Reduce the digest payload to the facts the model is allowed to talk about.
     *
     * Dashboard URLs and internal identifiers are stripped — they add tokens and
     * give the model nothing useful to say.
     *
     * @param  array<string, mixed>  $digest  Digest payload.
     * @return array<string, mixed> Trimmed fact sheet.
     */
    private function facts(array $digest): array
    {
        return [
            'fecha_corte' => $digest['as_of']->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY, HH:mm'),
            'corte_anterior' => $digest['since']->locale('es')->isoFormat('dddd D [de] MMMM'),
            'totales' => $digest['totals'],
            'requieren_atencion' => array_map(fn (array $a): array => [
                'codigo' => $a['code'],
                'empresa' => $a['company'],
                'severidad' => $a['severity'] === 'overdue' ? 'atrasado' : 'aviso',
                'motivo' => $a['reason'],
                'dias' => $a['days'],
            ], $digest['alerts']['items']),
            'atenciones_no_listadas' => $digest['alerts']['overflow'],
            'mas_antiguos_en_su_etapa' => array_map(fn (array $r): array => [
                'codigo' => $r['code'],
                'empresa' => $r['company'],
                'etapa' => $r['stage']->label(),
                'dias_en_etapa' => $r['days_in_stage'],
                'dias_totales' => $r['days_total'],
                'responsable' => $r['owner'] ?? 'sin asignar',
            ], $digest['oldest']),
            'distribucion_por_etapa' => $digest['distribution'],
            'movimientos_desde_el_corte_anterior' => $digest['movements'],
        ];
    }

    /**
     * JSON schema the response is constrained to, so the result is always parseable.
     *
     * @return array<string, mixed> JSON Schema for the briefing object.
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'greeting' => [
                    'type' => 'string',
                    'description' => 'Saludo de una frase que empieza con "Buenos días" y explica qué es el reporte.',
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'Dos a cuatro frases sobre el estado de la cartera hoy.',
                ],
                'priorities' => [
                    'type' => 'array',
                    'description' => 'Dos o tres acciones concretas para hoy, una frase cada una.',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['greeting', 'summary', 'priorities'],
            'additionalProperties' => false,
        ];
    }

    // -------------------------------------------------------------------------
    // Private — response handling
    // -------------------------------------------------------------------------

    /**
     * Extract the briefing from the API response.
     *
     * The response may open with thinking blocks, so the text block is located by
     * type rather than by position.
     *
     * @param  array<string, mixed>|null  $body  Decoded API response.
     * @return array{greeting: string, summary: string, priorities: array<int, string>}|null
     *                                                                                       The briefing, or null when the response could not be used.
     */
    private function parse(?array $body): ?array
    {
        if (($body['stop_reason'] ?? null) === 'refusal') {
            Log::warning('DailyDigestNarrator: Claude declined the request; sending the digest without a narrative.');

            return null;
        }

        $text = null;

        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text = $block['text'] ?? null;
                break;
            }
        }

        $parsed = $text === null ? null : json_decode($text, associative: true);

        if (! is_array($parsed) || ! isset($parsed['greeting'], $parsed['summary'])) {
            Log::warning('DailyDigestNarrator: unexpected response shape; sending the digest without a narrative.', [
                'raw_text' => $text,
            ]);

            return null;
        }

        return [
            'greeting' => (string) $parsed['greeting'],
            'summary' => (string) $parsed['summary'],
            'priorities' => array_values(array_filter(
                array_map('strval', $parsed['priorities'] ?? []),
                fn (string $p): bool => trim($p) !== '',
            )),
        ];
    }
}
