<?php

namespace App\Http\Controllers\Api\V3;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V3\RegistrationResource;
use App\Models\Registration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exposes registration data to authenticated API consumers (Singapur relay and notary team).
 *
 * Endpoints are read-only from the API perspective — all data mutations happen
 * via the webhook endpoint (inbound from relay) or via the Filament dashboard.
 */
class RegistrationController extends Controller
{
    /**
     * Return a paginated list of all registrations.
     *
     * Eager loads related entities to avoid N+1 queries.
     * Results are ordered by most recently created first.
     *
     * @param  Request  $request  Incoming HTTP request; supports ?per_page=N (max 100).
     * @return AnonymousResourceCollection  Paginated collection of RegistrationResource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $registrations = Registration::with(['shareholders', 'legalNames', 'documents'])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return RegistrationResource::collection($registrations);
    }

    /**
     * Return a single registration identified by its Singapur client code.
     *
     * The singapur_client_code (e.g., '000001') is the natural key used by the relay.
     * Eager loads all related entities so the relay can render a full status view.
     *
     * @param  string  $singapurClientCode  The registration number from the relay system.
     * @return JsonResponse                 RegistrationResource on success, 404 if not found.
     */
    public function show(string $singapurClientCode): JsonResponse
    {
        $registration = Registration::with(['shareholders', 'legalNames', 'documents'])
            ->where('singapur_client_code', $singapurClientCode)
            ->first();

        if ($registration === null) {
            return response()->json(
                ['error' => 'Registration not found'],
                Response::HTTP_NOT_FOUND,
            );
        }

        return response()->json(
            ['data' => new RegistrationResource($registration)],
            Response::HTTP_OK,
        );
    }
}
