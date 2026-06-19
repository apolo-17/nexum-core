<?php

declare(strict_types=1);

namespace App\Services\LegalName;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Queries the MUA (Módulo de Uso de Apartado) public portal of the Secretaría de Economía
 * to check whether a proposed company denomination is available for reservation.
 *
 * This endpoint is public and requires no authentication.
 * An empty `data` array in the response means the name is available.
 */
class CheckMuaAvailabilityService
{
    /**
     * MUA portal endpoints tried in order — both return the same DataTables payload.
     *
     * @var list<string>
     */
    private const MUA_URLS = [
        'https://mua.economia.gob.mx/mua-web/consultarAutorizadas',
        'https://mua.economia.gob.mx/mua-web/showAutorizadasHome',
    ];

    /**
     * HTTP timeout in seconds for each MUA request.
     *
     * @var int
     */
    private const TIMEOUT_SECONDS = 8;

    /**
     * Check whether the given denomination is available in the SE registry.
     *
     * Returns true when the name is available (not yet registered or reserved),
     * false when it is already taken, and null when the MUA portal is unreachable.
     *
     * @param  string  $name  Proposed company denomination (plain text, no special characters).
     *
     * @return bool|null  True = available, false = taken, null = MUA unreachable.
     */
    public function check(string $name): ?bool
    {
        if (! $this->hasValidCharacters($name)) {
            Log::warning('MUA availability check skipped: invalid characters in denomination.', [
                'name' => $name,
            ]);

            return false;
        }

        $payload = $this->buildPayload($name);

        foreach (self::MUA_URLS as $url) {
            $result = $this->requestMua($url, $payload, $name);

            if ($result !== null) {
                return $result;
            }
        }

        Log::error('MUA availability check failed: all endpoints unreachable.', ['name' => $name]);

        return null;
    }

    /**
     * Build the form payload for the MUA DataTables request.
     *
     * @param  string  $name  Denomination to search.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(string $name): array
    {
        return [
            'razonSocial'              => $name,
            'draw'                     => 2,
            'columns[0][data]'         => 'name',
            'columns[0][searchable]'   => 'true',
            'columns[0][orderable]'    => 'true',
            'order[0][column]'         => 0,
            'order[0][dir]'            => 'asc',
            'start'                    => 0,
            'length'                   => 10,
            'search[regex]'            => 'false',
        ];
    }

    /**
     * Perform a single HTTP request to a MUA endpoint and interpret the response.
     *
     * Returns true (available), false (taken), or null (endpoint error / unavailable).
     *
     * @param  string               $url      MUA endpoint URL.
     * @param  array<string, mixed> $payload  Form parameters.
     * @param  string               $name     Denomination being checked (for logging).
     *
     * @return bool|null
     */
    private function requestMua(string $url, array $payload, string $name): ?bool
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->asForm()
                ->post($url, $payload);

            if ($response->status() === 503 || $response->status() === 500) {
                Log::warning('MUA endpoint returned server error.', [
                    'url'    => $url,
                    'status' => $response->status(),
                ]);

                return null;
            }

            if ($response->successful() && isset($response['data'])) {
                $available = empty($response['data']);

                Log::info('MUA availability check completed.', [
                    'name'      => $name,
                    'available' => $available,
                    'url'       => $url,
                ]);

                return $available;
            }
        } catch (\Throwable $th) {
            Log::error('MUA request exception.', [
                'url'       => $url,
                'name'      => $name,
                'exception' => $th->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Validate that the denomination contains only letters, digits, and spaces.
     *
     * The MUA portal rejects names with special characters before even querying.
     *
     * @param  string  $name  Denomination to validate.
     *
     * @return bool
     */
    private function hasValidCharacters(string $name): bool
    {
        return (bool) preg_match('/^[\p{L}0-9\s]+$/u', $name);
    }
}
