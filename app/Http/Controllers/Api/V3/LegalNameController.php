<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V3;

use App\Enums\LegalNameStatusEnum;
use App\Http\Controllers\Controller;
use App\Models\LegalName;
use App\Models\Registration;
use App\Services\LegalName\CheckMuaAvailabilityService;
use App\Services\LegalName\CreateLegalNameService;
use App\Services\LegalName\DeleteLegalNameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manages company denomination proposals linked to a registration.
 *
 * Public endpoint:
 *   POST /api/v3/legal-name/check-availability — used by the Singapur relay so
 *   Chinese clients can verify name availability against the MUA portal before submitting.
 *
 * Protected endpoints (JWT required):
 *   POST   /api/v3/registrations/{code}/legal-names         — add a denomination
 *   DELETE /api/v3/registrations/{code}/legal-names/{id}    — remove a denomination
 */
class LegalNameController extends Controller
{
    /**
     * @param  CheckMuaAvailabilityService  $checkMuaAvailabilityService  MUA public portal query.
     * @param  CreateLegalNameService       $createLegalNameService        Domain service.
     * @param  DeleteLegalNameService       $deleteLegalNameService        Domain service.
     */
    public function __construct(
        private readonly CheckMuaAvailabilityService $checkMuaAvailabilityService,
        private readonly CreateLegalNameService $createLegalNameService,
        private readonly DeleteLegalNameService $deleteLegalNameService,
    ) {}

    // -------------------------------------------------------------------------
    // Public
    // -------------------------------------------------------------------------

    /**
     * Check whether a proposed denomination is available in the MUA registry.
     *
     * This endpoint is intentionally public (no JWT required) so the Singapur relay
     * can query it directly when the Chinese client is choosing company names.
     *
     * @param  Request  $request  Body: { "name": string }
     *
     * @return JsonResponse
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:150'],
        ]);

        $name = trim($request->string('name')->toString());
        $available = $this->checkMuaAvailabilityService->check($name);

        if ($available === null) {
            return response()->json([
                'available' => null,
                'message'   => 'El portal del MUA no está disponible en este momento. Intenta de nuevo más tarde.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json([
            'available' => $available,
            'name'      => $name,
        ], Response::HTTP_OK);
    }

    // -------------------------------------------------------------------------
    // Protected (JWT)
    // -------------------------------------------------------------------------

    /**
     * Add a denomination proposal to a registration.
     *
     * Rules:
     *  - Maximum 4 denominations per registration.
     *  - No modifications when an approved denomination already exists.
     *  - Priority must be between 1 and 4.
     *
     * @param  Request       $request  Body: { "name": string, "priority": int, "mua_available": bool|null }
     * @param  Registration  $registration  Route model binding via singapurClientCode.
     *
     * @return JsonResponse
     */
    public function store(Request $request, Registration $registration): JsonResponse
    {
        $request->validate([
            'name'          => ['required', 'string', 'min:3', 'max:150'],
            'priority'      => ['required', 'integer', 'min:1', 'max:4'],
            'mua_available' => ['nullable', 'boolean'],
        ]);

        // Block modifications when there is already an approved denomination.
        if ($registration->legalNames()->where('status', LegalNameStatusEnum::APPROVED->value)->exists()) {
            return response()->json([
                'message' => 'Este expediente ya tiene una denominación aprobada.',
            ], Response::HTTP_CONFLICT);
        }

        if ($registration->legalNames()->count() >= 4) {
            return response()->json([
                'message' => 'Se alcanzó el máximo de 4 propuestas de denominación.',
            ], Response::HTTP_CONFLICT);
        }

        $legalName = $this->createLegalNameService->handle($registration, $request->only([
            'name',
            'priority',
            'mua_available',
        ]));

        return response()->json([
            'data' => [
                'id'       => $legalName->id,
                'name'     => $legalName->name,
                'priority' => $legalName->priority,
                'status'   => $legalName->status->value,
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Remove a denomination proposal from a registration.
     *
     * Rules:
     *  - Minimum 3 denominations must remain after deletion.
     *  - Cannot delete a denomination that is in PROCESS or APPROVED state.
     *
     * @param  Registration  $registration  Route model binding.
     * @param  LegalName     $legalName     Route model binding.
     *
     * @return JsonResponse
     */
    public function destroy(Registration $registration, LegalName $legalName): JsonResponse
    {
        // Ensure the denomination belongs to this registration.
        if ($legalName->registration_id !== $registration->id) {
            return response()->json([
                'message' => 'La denominación no pertenece a este expediente.',
            ], Response::HTTP_NOT_FOUND);
        }

        if (! $legalName->isEditable()) {
            return response()->json([
                'message' => 'No se puede eliminar una denominación que ya está en proceso o aprobada.',
            ], Response::HTTP_CONFLICT);
        }

        if ($registration->legalNames()->count() <= 3) {
            return response()->json([
                'message' => 'Se requieren mínimo 3 propuestas de denominación.',
            ], Response::HTTP_CONFLICT);
        }

        $this->deleteLegalNameService->handle($legalName);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
