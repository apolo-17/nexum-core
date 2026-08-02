<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V3;

use App\Enums\DocumentTypeEnum;
use App\Enums\RegistrationStageEnum;
use App\Enums\ShareholderRoleEnum;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Registration;
use App\Models\Shareholder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
     * Complete an expediente: apply verified fields, upsert shareholders, store documents.
     *
     * Idempotent and safe to re-run: registration fields are only overwritten with the
     * values provided; shareholders are matched by name (updated, not duplicated); and a
     * document is skipped if one with the same name already exists. Every change is logged.
     *
     * Body shape:
     *   {
     *     "registration": { "company_object": "...", "capital_social": 10000,
     *                       "fiscal_street": "...", "fiscal_ext_number": "2", ... },
     *     "shareholders": [ { "name": "...", "participation_percentage": 99,
     *                         "passport_number": "...", "tax_id": "...", "role": "...", ... } ],
     *     "documents":    [ { "type": "acta_signed", "name": "...pdf",
     *                         "content_base64": "..." } ]
     *   }
     *
     * @param  Request  $request  Request carrying the X-Intake-Token header and body.
     * @param  string  $ref  The expediente reference.
     */
    public function complete(Request $request, string $ref): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $registration = $this->resolve($ref);

        if ($registration === null) {
            return response()->json(['error' => 'Expediente no encontrado', 'ref' => $ref], Response::HTTP_NOT_FOUND);
        }

        $applied = ['fields' => [], 'shareholders' => [], 'documents' => []];

        DB::transaction(function () use ($request, $registration, &$applied): void {
            $applied['fields'] = $this->applyRegistrationFields($registration, (array) $request->input('registration', []));
            $applied['shareholders'] = $this->upsertShareholders($registration, (array) $request->input('shareholders', []));
            $applied['documents'] = $this->storeDocuments($registration, (array) $request->input('documents', []));
        });

        Log::info('Intake: expediente completed.', [
            'registration_id' => $registration->id,
            'client_code' => $registration->singapur_client_code,
            'applied' => $applied,
        ]);

        $registration->refresh()->load(['shareholders', 'legalNames', 'documents', 'primaryLegalName']);

        return response()->json([
            'applied' => $applied,
            'data' => $this->present($registration),
        ], Response::HTTP_OK);
    }

    /**
     * Update only the registration fields that were provided (non-null).
     *
     * @param  Registration  $registration  The expediente to update.
     * @param  array<string, mixed>  $fields  Whitelisted fields from the request.
     * @return list<string> Names of the fields that were actually changed.
     */
    private function applyRegistrationFields(Registration $registration, array $fields): array
    {
        $allowed = [
            'company_type', 'company_object', 'capital_social',
            'fiscal_street', 'fiscal_ext_number', 'fiscal_int_number', 'fiscal_neighborhood',
            'fiscal_municipality', 'fiscal_state', 'fiscal_postal_code',
        ];

        $updates = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $fields) && $fields[$field] !== null && $fields[$field] !== '') {
                $updates[$field] = $fields[$field];
            }
        }

        if ($updates !== []) {
            $registration->update($updates);
        }

        return array_keys($updates);
    }

    /**
     * Upsert shareholders by name: update the matching one, or create it.
     *
     * Matching by (case-insensitive) name avoids duplicating the shareholders the team
     * pre-loaded to schedule the SAT appointment.
     *
     * @param  Registration  $registration  The expediente.
     * @param  array<int, array<string, mixed>>  $shareholders  Shareholder payloads.
     * @return list<array{name:string, action:string}> What happened to each.
     */
    private function upsertShareholders(Registration $registration, array $shareholders): array
    {
        $existing = $registration->shareholders()->get();
        $result = [];

        foreach ($shareholders as $data) {
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $attrs = array_filter([
                'nationality' => $data['nationality'] ?? null,
                'passport_number' => $data['passport_number'] ?? null,
                'participation_percentage' => $data['participation_percentage'] ?? null,
                'role' => isset($data['role']) ? ShareholderRoleEnum::tryFrom((string) $data['role']) : null,
                'email' => $data['email'] ?? null,
                'is_married' => $data['is_married'] ?? null,
                'gender' => $data['gender'] ?? null,
                'birthdate' => $data['birthdate'] ?? null,
                'birthplace' => $data['birthplace'] ?? null,
                'civil_status' => $data['civil_status'] ?? null,
                'tax_id' => $data['tax_id'] ?? null,
                'address_line' => $data['address_line'] ?? null,
            ], fn ($v) => $v !== null);

            $match = $existing->first(fn ($s) => mb_strtolower(trim((string) $s->name)) === mb_strtolower($name));

            if ($match !== null) {
                $match->update($attrs);
                $result[] = ['name' => $name, 'action' => 'updated'];
            } else {
                Shareholder::create(['registration_id' => $registration->id, 'name' => $name] + $attrs);
                $result[] = ['name' => $name, 'action' => 'created'];
            }
        }

        return $result;
    }

    /**
     * Store uploaded documents and create their Document records.
     *
     * Skips a document whose name already exists on the expediente (idempotent). Files
     * land on the default disk (R2 in production) under documents/{registration_id}/.
     *
     * @param  Registration  $registration  The expediente.
     * @param  array<int, array<string, mixed>>  $documents  Document payloads.
     * @return list<array{name:string, type:string, action:string}> What happened to each.
     */
    private function storeDocuments(Registration $registration, array $documents): array
    {
        $result = [];

        foreach ($documents as $doc) {
            $name = trim((string) ($doc['name'] ?? ''));
            $type = DocumentTypeEnum::tryFrom((string) ($doc['type'] ?? '')) ?? DocumentTypeEnum::OTHER;
            $base64 = (string) ($doc['content_base64'] ?? '');

            if ($name === '' || $base64 === '') {
                continue;
            }

            $exists = Document::where('registration_id', $registration->id)->where('name', $name)->exists();
            if ($exists) {
                $result[] = ['name' => $name, 'type' => $type->value, 'action' => 'skipped_exists'];

                continue;
            }

            $binary = base64_decode($base64, strict: true);
            if ($binary === false) {
                $result[] = ['name' => $name, 'type' => $type->value, 'action' => 'skipped_bad_base64'];

                continue;
            }

            $path = "documents/{$registration->id}/".Str::random(8).'_'.$name;
            Storage::put($path, $binary);

            Document::create([
                'registration_id' => $registration->id,
                'type' => $type,
                'name' => $name,
                'storage_path' => $path,
                'stage' => $registration->getRawOriginal('stage'),
                'shareholder_index' => $doc['shareholder_index'] ?? null,
                // Notary-approved documents arrive already verified.
                'verified_at' => now(),
            ]);

            $result[] = ['name' => $name, 'type' => $type->value, 'action' => 'stored'];
        }

        return $result;
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
