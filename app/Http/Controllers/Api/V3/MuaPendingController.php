<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V3;

use App\Enums\LegalNameStatusEnum;
use App\Http\Controllers\Controller;
use App\Models\LegalName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exposes the list of denominations pending SE status polling to the MUA bot.
 *
 * The bot calls GET /api/v3/mua-bot/pending on its poll cycle to retrieve which
 * denominations it should check in the SE portal, along with the FIEL credentials
 * of the soldado assigned to each one. Authentication uses the shared API key
 * (X-Bot-Api-Key header) — the same key Laravel sends when calling /submit.
 */
class MuaPendingController extends Controller
{
    /**
     * Return denominations in PENDING or PROCESS status with their FIEL credentials.
     *
     * Only denominations that already have a FIEL account assigned are included.
     * The bot uses the cert/key/password to authenticate against the SE portal
     * and check the current status of each denomination.
     *
     * @param  Request  $request  Incoming request with X-Bot-Api-Key header.
     * @return JsonResponse List of pending denominations or 401 if unauthorized.
     */
    public function index(Request $request): JsonResponse
    {
        $apiKey = $request->header('X-Bot-Api-Key');

        if (! $apiKey || ! hash_equals((string) config('services.mua_bot.api_key'), (string) $apiKey)) {
            return response()->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $pending = LegalName::whereIn('status', [
            LegalNameStatusEnum::PENDING->value,
            LegalNameStatusEnum::PROCESS->value,
        ])
            ->whereNotNull('soldado_id')
            ->with('soldado')
            ->get()
            ->map(fn (LegalName $legalName): array => [
                'legal_name_id' => $legalName->id,
                'denomination' => $legalName->name,
                'soldier_id' => $legalName->soldado_id,
                'cert_base64' => $legalName->soldado?->getCredential('certificate'),
                'key_base64' => $legalName->soldado?->getCredential('private_key'),
                'password' => $legalName->soldado?->getCredential('password'),
            ]);

        return response()->json(['data' => $pending], Response::HTTP_OK);
    }
}
