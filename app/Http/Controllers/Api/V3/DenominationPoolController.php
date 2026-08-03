<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V3;

use App\Enums\LegalNameStatusEnum;
use App\Http\Controllers\Controller;
use App\Models\LegalName;
use App\Models\Registration;
use App\Services\Denomination\ClaimPoolDenominationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exposes the pool of SE-approved denominations for the China/Singapur front.
 *
 * Instead of reacting to denomination requests per expedient, Nexum pre-approves
 * a stock of names with the SE. The client front lists the available approved
 * names and claims one for a given registration.
 */
class DenominationPoolController extends Controller
{
    /**
     * Same list as available(), but for the China/Singapur front — guarded by a shared
     * header token (X-Pool-Token) instead of JWT, so their server can read our live pool
     * and stop maintaining a disconnected local copy of available names.
     *
     * @param  Request  $request  Request carrying the X-Pool-Token header.
     */
    public function availableForRelay(Request $request): JsonResponse
    {
        $token = (string) $request->header('X-Pool-Token');
        $expected = (string) config('services.denomination_pool.token');

        if ($expected === '' || $token === '' || ! hash_equals($expected, $token)) {
            return response()->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->available();
    }

    /**
     * List the approved pool denominations that are still available to claim.
     *
     * Available means: no registration assigned and status APPROVED.
     */
    public function available(): JsonResponse
    {
        $names = LegalName::query()
            ->whereNull('registration_id')
            ->where('status', LegalNameStatusEnum::APPROVED->value)
            ->orderBy('authorization_timestamp')
            ->get()
            ->map(fn (LegalName $name): array => [
                'id' => $name->id,
                'name' => $name->name,
                'company_type' => $name->company_type,
                'folio' => $name->clave_unica_denominacion,
                'authorized_at' => $name->authorization_timestamp?->toIso8601String(),
            ]);

        return response()->json(['data' => $names], Response::HTTP_OK);
    }

    /**
     * Claim an available approved denomination for a registration.
     *
     * Assigns the pool name to the registration identified by its singapur client
     * code, which removes it from the available pool. Guards against double-claim
     * with a row lock and re-check inside the transaction.
     *
     * @param  Request  $request  Carries the target registration code.
     * @param  LegalName  $legalName  The pool denomination being claimed (route-bound).
     * @return JsonResponse 200 on success, 404 / 409 / 422 on validation failures.
     */
    public function claim(Request $request, LegalName $legalName): JsonResponse
    {
        $validated = $request->validate([
            'registration_code' => ['required', 'string'],
        ]);

        $registration = Registration::where('singapur_client_code', $validated['registration_code'])->first();

        if ($registration === null) {
            return response()->json(
                ['error' => 'Registration not found.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        // Same claim the dashboard runs: assigns the name as priority 1 and copies
        // its SE constancia into the expedient's documents.
        $result = app(ClaimPoolDenominationService::class)->claim($legalName, $registration);

        if (! $result->claimed) {
            return response()->json(
                ['error' => 'Denomination is no longer available.'],
                Response::HTTP_CONFLICT,
            );
        }

        return response()->json([
            'data' => [
                'id' => $legalName->id,
                'name' => $legalName->name,
                'registration_code' => $registration->singapur_client_code,
                'constancia_attached' => $result->constanciaAttached,
            ],
        ], Response::HTTP_OK);
    }
}
