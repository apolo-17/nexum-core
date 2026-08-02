<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V3;

use App\Enums\DocumentTypeEnum;
use App\Http\Controllers\Controller;
use App\Models\Registration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manual intake API to inspect and complete production expedientes.
 *
 * The Singapur relay started sending incomplete submissions, so the team completes those
 * expedientes by hand from the notary-approved documents (acta, passports, CSF…). This
 * controller is the tooling for that: read an expediente's current state to see what is
 * missing, and (later) complete it. Protected by a dedicated token (X-Intake-Token) that
 * only the team holds — separate from the bot keys.
 *
 * Read-only for now; the "complete" endpoint is added once we have seen a real expediente
 * and know exactly what needs filling.
 */
class IntakeController extends Controller
{
    /**
     * Return the full current state of an expediente (production), so the operator and
     * the assistant can see what exists and what is missing before completing it.
     *
     * `ref` is resolved as the Singapur client code (e.g. "000001") first — that is the
     * natural key — then falls back to the ULID and the package id for flexibility.
     *
     * @param  Request  $request  Request carrying the X-Intake-Token header.
     * @param  string  $ref  The expediente reference (client code, id, or package id).
     */
    public function show(Request $request, string $ref): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $registration = $this->resolve($ref);

        if ($registration === null) {
            return response()->json(['error' => 'Expediente no encontrado', 'ref' => $ref], Response::HTTP_NOT_FOUND);
        }

        $registration->load(['shareholders', 'legalNames', 'documents', 'primaryLegalName']);

        return response()->json(['data' => $this->present($registration)], Response::HTTP_OK);
    }

    /**
     * Verify the dedicated intake token (timing-safe).
     *
     * @param  Request  $request  The incoming request.
     */
    private function authorized(Request $request): bool
    {
        $token = (string) $request->header('X-Intake-Token');
        $expected = (string) config('services.intake.token');

        return $expected !== '' && $token !== '' && hash_equals($expected, $token);
    }

    /**
     * Find a registration by client code, then ULID, then package id.
     *
     * @param  string  $ref  The reference to resolve.
     */
    private function resolve(string $ref): ?Registration
    {
        return Registration::where('singapur_client_code', $ref)
            ->orWhere('id', $ref)
            ->orWhere('singapur_package_id', $ref)
            ->first();
    }

    /**
     * Shape the expediente into a readable snapshot: fields, shareholders, denomination,
     * the documents it already has, and which expected document types are missing.
     *
     * @param  Registration  $registration  The loaded registration.
     * @return array<string, mixed>
     */
    private function present(Registration $registration): array
    {
        // Read RAW values for every enum-cast field. This inspector is meant for
        // incomplete or messy expedientes, so an invalid stage/status/type/role value in
        // the DB must not break the read — accessing the cast enum would throw a ValueError.
        $presentTypes = $registration->documents
            ->map(fn ($d) => $d->getRawOriginal('type'))
            ->filter()->unique()->values();

        // The document types a complete expediente is generally expected to carry.
        $expectedTypes = [
            DocumentTypeEnum::PASSPORT->value,
            DocumentTypeEnum::ACTA_SIGNED->value,
            DocumentTypeEnum::CSF->value,
            DocumentTypeEnum::PROOF_OF_ADDRESS_MX->value,
            DocumentTypeEnum::RFC_DOCUMENT->value,
        ];

        return [
            'id' => $registration->id,
            'client_code' => $registration->singapur_client_code,
            'package_id' => $registration->singapur_package_id,
            'folder_name' => $registration->singapur_folder_name,
            'stage' => $registration->getRawOriginal('stage'),
            'status' => $registration->getRawOriginal('status'),
            'company' => [
                'denomination' => $registration->primaryLegalName?->name,
                'company_type' => $registration->company_type,
                'company_object' => $registration->company_object,
                'capital_social' => $registration->capital_social,
                'rfc' => $registration->rfc,
            ],
            'fiscal_address' => [
                'street' => $registration->fiscal_street,
                'ext_number' => $registration->fiscal_ext_number,
                'int_number' => $registration->fiscal_int_number,
                'neighborhood' => $registration->fiscal_neighborhood,
                'municipality' => $registration->fiscal_municipality,
                'state' => $registration->fiscal_state,
                'postal_code' => $registration->fiscal_postal_code,
            ],
            'shareholders' => $registration->shareholders->map(fn ($s): array => [
                'name' => $s->name,
                'nationality' => $s->nationality,
                'passport_number' => $s->passport_number,
                'participation_percentage' => $s->participation_percentage,
                'role' => $s->getRawOriginal('role'),
                'email' => $s->email,
                'is_married' => $s->is_married,
                'tax_id' => $s->tax_id,
            ])->all(),
            'legal_names' => $registration->legalNames->map(fn ($n): array => [
                'name' => $n->name,
                'priority' => $n->priority,
                'status' => $n->getRawOriginal('status'),
            ])->all(),
            'documents' => $registration->documents->map(fn ($d): array => [
                'type' => $d->getRawOriginal('type'),
                'name' => $d->name,
                'shareholder_index' => $d->shareholder_index,
                'has_file' => filled($d->storage_path),
            ])->all(),
            'documents_missing' => collect($expectedTypes)->diff($presentTypes)->values()->all(),
        ];
    }
}
