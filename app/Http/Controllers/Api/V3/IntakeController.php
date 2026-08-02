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
     * List every expediente with a compact summary, to review what they all carry.
     *
     * Useful when some folders only bring shareholder documents: the denomination and
     * the shareholder names identify which company each set of documents belongs to.
     *
     * @param  Request  $request  Request carrying the X-Intake-Token header.
     */
    public function index(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $registrations = Registration::query()
            ->with(['shareholders', 'primaryLegalName', 'documents'])
            ->orderBy('singapur_client_code')
            ->get();

        $data = $registrations->map(fn (Registration $r): array => [
            'client_code' => $r->singapur_client_code,
            'id' => $r->id,
            'denomination' => $r->primaryLegalName?->name,
            'company_type' => $r->company_type,
            'stage' => $r->getRawOriginal('stage'),
            'has_object' => filled($r->company_object),
            'has_capital' => filled($r->capital_social),
            'has_fiscal_address' => filled($r->fiscal_street),
            'shareholders' => $r->shareholders->map(fn ($s): array => [
                'name' => $s->name,
                'participation_percentage' => $s->participation_percentage,
                'role' => $s->getRawOriginal('role'),
            ])->all(),
            'documents' => $r->documents->map(fn ($d) => $d->getRawOriginal('type'))->filter()->unique()->values()->all(),
        ])->all();

        return response()->json(['count' => count($data), 'data' => $data], Response::HTTP_OK);
    }

    /**
     * List the soldados (potential legal representatives) registered in production.
     *
     * The legal representatives named in each company's acta must already exist here as
     * soldados to be assignable to that company's SAT appointment. This lets us match the
     * acta's legal reps against who is actually in the system.
     *
     * @param  Request  $request  Request carrying the X-Intake-Token header.
     */
    public function soldados(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $soldados = \App\Models\Soldado::query()->orderBy('name')->get();

        $data = $soldados->map(fn ($s): array => [
            'id' => $s->id,
            'name' => $s->name,
            'rfc' => $s->rfc,
            'curp' => $s->curp,
            'email' => $s->email,
            'phone' => $s->phone,
            'is_active' => (bool) $s->is_active,
            'available_as_legal_representative' => (bool) $s->available_as_legal_representative,
        ])->all();

        return response()->json(['count' => count($data), 'data' => $data], Response::HTTP_OK);
    }

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

        $applied = ['fields' => [], 'denomination' => [], 'shareholders' => [], 'documents' => []];

        // "replace" wipes the pre-loaded shareholders and inserts the acta list verbatim.
        // The team seeded placeholder socios (sometimes with names in Chinese characters) just
        // to schedule the SAT cita; when the notarized acta is at hand we swap them wholesale
        // for the romanized names + passports the SAT needs, without leaving duplicates.
        $shareholdersMode = (string) $request->input('shareholders_mode', 'upsert');

        DB::transaction(function () use ($request, $registration, $shareholdersMode, &$applied): void {
            $applied['fields'] = $this->applyRegistrationFields($registration, (array) $request->input('registration', []));
            $applied['denomination'] = $this->applyDenomination($registration, (array) $request->input('denomination', []));
            $applied['shareholders'] = $shareholdersMode === 'replace'
                ? $this->replaceShareholders($registration, (array) $request->input('shareholders', []))
                : $this->upsertShareholders($registration, (array) $request->input('shareholders', []));
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
     * Correct the expediente's primary denomination from its SE authorization constancia.
     *
     * Only touches the priority-1 legal name (the one assigned to the company). Fills the
     * clave única (CUD), the authorization timestamp and, when the SE already resolved it,
     * flips a lingering `wait`/`process` status to `approved`. Never creates a legal name.
     *
     * @param  Registration  $registration  The expediente.
     * @param  array<string, mixed>  $data  { clave_unica_denominacion, authorization_timestamp, status, company_type }
     * @return array<string, mixed> What was changed (empty if nothing / no primary name).
     */
    private function applyDenomination(Registration $registration, array $data): array
    {
        if ($data === []) {
            return [];
        }

        $legalName = $registration->legalNames()->orderBy('priority')->first();
        if ($legalName === null) {
            return ['skipped' => 'no_primary_legal_name'];
        }

        $updates = [];
        if (filled($data['clave_unica_denominacion'] ?? null)) {
            $updates['clave_unica_denominacion'] = (string) $data['clave_unica_denominacion'];
        }
        if (filled($data['authorization_timestamp'] ?? null)) {
            $updates['authorization_timestamp'] = $data['authorization_timestamp'];
        }
        if (filled($data['company_type'] ?? null)) {
            $updates['company_type'] = (string) $data['company_type'];
        }
        if (filled($data['status'] ?? null) && \App\Enums\LegalNameStatusEnum::tryFrom((string) $data['status']) !== null) {
            $updates['status'] = (string) $data['status'];
        }

        if ($updates !== []) {
            $legalName->update($updates);
        }

        return ['name' => $legalName->name, 'changed' => array_keys($updates)];
    }

    /**
     * Replace all shareholders with the given list (delete existing, insert fresh).
     *
     * Used when the notarized acta supersedes whatever placeholder socios were pre-loaded.
     * nationality and role are NOT NULL in the schema, so both get safe defaults when the
     * payload omits them (this intake batch is Chinese-owned e-commerce companies).
     *
     * @param  Registration  $registration  The expediente.
     * @param  array<int, array<string, mixed>>  $shareholders  Shareholder payloads.
     * @return list<array{name:string, action:string}> What happened to each.
     */
    private function replaceShareholders(Registration $registration, array $shareholders): array
    {
        if ($shareholders === []) {
            return [];
        }

        $registration->shareholders()->delete();

        $result = [];
        foreach ($shareholders as $data) {
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $attrs = array_filter([
                'passport_number' => $data['passport_number'] ?? null,
                'participation_percentage' => $data['participation_percentage'] ?? null,
                'email' => $data['email'] ?? null,
                'is_married' => $data['is_married'] ?? null,
                'gender' => $data['gender'] ?? null,
                'birthdate' => $data['birthdate'] ?? null,
                'birthplace' => $data['birthplace'] ?? null,
                'civil_status' => $data['civil_status'] ?? null,
                'tax_id' => $data['tax_id'] ?? null,
                'address_line' => $data['address_line'] ?? null,
            ], fn ($v) => $v !== null);

            $role = ShareholderRoleEnum::tryFrom((string) ($data['role'] ?? '')) ?? ShareholderRoleEnum::SHAREHOLDER;

            Shareholder::create([
                'registration_id' => $registration->id,
                'name' => $name,
                'nationality' => $data['nationality'] ?? 'China',
                'role' => $role,
            ] + $attrs);

            $result[] = ['name' => $name, 'action' => 'replaced'];
        }

        return $result;
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
                'clave_unica_denominacion' => $n->clave_unica_denominacion,
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
