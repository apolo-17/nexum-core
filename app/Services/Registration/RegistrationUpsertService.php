<?php

namespace App\Services\Registration;

use App\DTOs\SingapurFileDTO;
use App\DTOs\SingapurShareholderDTO;
use App\DTOs\SingapurSubmissionDTO;
use App\Enums\LegalNameStatusEnum;
use App\Enums\RegistrationStageEnum;
use App\Enums\RegistrationStatusEnum;
use App\Enums\ShareholderRoleEnum;
use App\Models\Document;
use App\Models\LegalName;
use App\Models\Registration;
use App\Services\Denomination\ClaimPoolDenominationService;
use App\Models\Shareholder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Creates or updates a Registration and all its related entities from a Singapur submission.
 *
 * Idempotent by design: calling upsert() with the same DTO produces the same result.
 * Uses singapur_client_code as the natural key to determine create vs. update.
 * The operation is wrapped in a DB transaction so partial failures leave no orphaned data.
 */
class RegistrationUpsertService
{
    /**
     * Maximum decoded size (bytes) accepted for a single webhook document. Larger
     * payloads are rejected to protect storage from abusive or malformed submissions.
     */
    private const MAX_FILE_BYTES = 25 * 1024 * 1024;

    /**
     * MIME types accepted from the relay. Anything else is recorded but not stored.
     *
     * @var list<string>
     */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        // DOCX — the pre-rendered acta (incorporation_deed) may arrive as a Word
        // template so Nexum can inject the legal representative + denomination.
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /**
     * Create or update a Registration from a parsed Singapur submission DTO.
     *
     * Upserts the Registration, its initial LegalName denomination, all Shareholders,
     * and Document metadata records. Google Drive fields are left null — uploading
     * to Drive is a separate manual step performed by the notary team.
     *
     * @param  SingapurSubmissionDTO  $dto  Parsed submission package.
     * @return Registration The created or updated registration.
     */
    public function upsert(SingapurSubmissionDTO $dto): Registration
    {
        return DB::transaction(function () use ($dto): Registration {
            $registration = $this->upsertRegistration($dto);
            $this->upsertInitialLegalName($registration, $dto->companyName, $dto->cud, $dto->denominationPoolId);
            $this->syncShareholders($registration, $dto->shareholders);
            $this->createDocumentMetadata($registration, $dto->files);

            // Persist the pre-rendered acta sent by China, when present.
            if ($dto->incorporationDeed !== null) {
                $this->createDocumentIfNotExists($registration, $dto->incorporationDeed);
            }

            return $registration;
        });
    }

    /**
     * Create or update the Registration record using singapur_client_code as the natural key.
     *
     * On create: stage defaults to DATA_RECEIVED, status to ACTIVE.
     * On update: only relay-controlled fields are refreshed (package id, company type).
     * Stage and status are never regressed by an update.
     *
     * @param  SingapurSubmissionDTO  $dto  Parsed submission package.
     */
    private function upsertRegistration(SingapurSubmissionDTO $dto): Registration
    {
        /** @var Registration $registration */
        $registration = Registration::firstOrCreate(
            ['singapur_client_code' => $dto->registrationNumber],
            [
                'singapur_package_id' => $dto->id,
                'singapur_folder_name' => $dto->companyFolderName,
                'company_type' => $dto->resolvedCompanyType(),
                'company_object' => $dto->companyObject,
                'capital_social' => $dto->capitalSocial,
                'stage' => RegistrationStageEnum::DATA_RECEIVED,
                'status' => RegistrationStatusEnum::ACTIVE,
            ],
        );

        // Refresh relay metadata on subsequent deliveries without touching stage/status.
        // Corporate fields (company_object, capital_social) are only updated when the relay
        // provides them, so manual edits by the notary team are not silently overwritten.
        if (! $registration->wasRecentlyCreated) {
            $updates = [
                'singapur_package_id' => $dto->id,
                'singapur_folder_name' => $dto->companyFolderName,
                'company_type' => $dto->resolvedCompanyType(),
            ];

            if (filled($dto->companyObject)) {
                $updates['company_object'] = $dto->companyObject;
            }

            if (filled($dto->capitalSocial)) {
                $updates['capital_social'] = $dto->capitalSocial;
            }

            $registration->update($updates);
        }

        return $registration;
    }

    /**
     * Create the first priority denomination if it does not already exist.
     *
     * The company name from the relay is always stored at priority 1 with status WAIT.
     * If a priority-1 name already exists it is updated only if it is still editable
     * (not in PROCESS or APPROVED state) to preserve notary team work.
     *
     * @param  Registration  $registration  Target registration.
     * @param  string  $companyName  Proposed company denomination from the relay.
     * @param  string|null  $cud  SE authorization CUD captured by China, if any.
     * @param  string|null  $poolId  Id of the pool denomination the client picked, if any.
     */
    private function upsertInitialLegalName(
        Registration $registration,
        string $companyName,
        ?string $cud = null,
        ?string $poolId = null,
    ): void {
        /** @var LegalName|null $existing */
        $existing = $registration->legalNames()->where('priority', 1)->first();

        // If the priority-1 name is already locked (SUBMITTING/PROCESS/APPROVED — e.g. a
        // pool name claimed on a previous delivery, or notary work), leave it untouched.
        if ($existing !== null && ! $existing->isEditable()) {
            return;
        }

        // Preferred path: the client picked a name from our pre-approved pool. Claim that
        // exact denomination (binds it to the expedient and copies the SE constancia in as a
        // document) instead of creating a fresh WAIT name that would be re-sent to the SE.
        // Match by pool id, then CUD, then the exact name — so even if China only sends the
        // name, an approved pool name is still recognized.
        $poolName = $this->resolvePoolDenomination($poolId, $cud, $companyName);
        if ($poolName !== null) {
            // Drop any stale editable WAIT name before claiming, to avoid two priority-1 rows.
            $existing?->delete();
            app(ClaimPoolDenominationService::class)->claim($poolName, $registration);

            return;
        }

        if (blank($companyName)) {
            return;
        }

        // Fallback: a plain WAIT denomination that still needs SE authorization.
        if ($existing === null) {
            LegalName::create([
                'registration_id' => $registration->id,
                'name' => $companyName,
                'priority' => 1,
                'status' => LegalNameStatusEnum::WAIT,
                'clave_unica_denominacion' => $cud ?: null,
            ]);

            return;
        }

        $existing->update(array_filter([
            'name' => $companyName,
            // Backfill the CUD when China provides it and we don't have one yet.
            'clave_unica_denominacion' => $existing->clave_unica_denominacion ?: ($cud ?: null),
        ], fn ($v) => $v !== null));
    }

    /**
     * Find an available pool denomination by id (preferred), then CUD, then exact name.
     *
     * Only matches names still in the pool: no expedient assigned and status APPROVED.
     *
     * @param  string|null  $poolId  Pool denomination id from the relay.
     * @param  string|null  $cud  SE authorization CUD from the relay.
     * @param  string|null  $companyName  Denomination name from the relay.
     */
    private function resolvePoolDenomination(?string $poolId, ?string $cud, ?string $companyName = null): ?LegalName
    {
        $base = LegalName::query()
            ->whereNull('registration_id')
            ->where('status', LegalNameStatusEnum::APPROVED->value);

        if (filled($poolId)) {
            $byId = (clone $base)->whereKey($poolId)->first();
            if ($byId !== null) {
                return $byId;
            }
        }

        if (filled($cud)) {
            $byCud = (clone $base)->where('clave_unica_denominacion', $cud)->first();
            if ($byCud !== null) {
                return $byCud;
            }
        }

        if (filled($companyName)) {
            return (clone $base)
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($companyName))])
                ->first();
        }

        return null;
    }

    /**
     * Replace all existing shareholders with the data received from the relay.
     *
     * On first delivery this creates fresh records. On redelivery it deletes the
     * existing ones and recreates them so the data always mirrors the relay.
     * The first shareholder (index 1) is assigned the LEGAL_REPRESENTATIVE role
     * by default; the rest are SHAREHOLDER.
     *
     * @param  Registration  $registration  Target registration.
     * @param  list<SingapurShareholderDTO>  $shareholders  Parsed shareholder DTOs.
     */
    private function syncShareholders(Registration $registration, array $shareholders): void
    {
        // Wipe existing shareholders to avoid duplicates on redelivery.
        $registration->shareholders()->delete();

        foreach ($shareholders as $dto) {
            $this->createShareholder($registration, $dto);
        }
    }

    /**
     * Persist a single shareholder record.
     *
     * @param  Registration  $registration  Parent registration.
     * @param  SingapurShareholderDTO  $dto  Shareholder data from the relay.
     */
    private function createShareholder(Registration $registration, SingapurShareholderDTO $dto): Shareholder
    {
        // Shareholder at index 1 becomes the default legal representative.
        $role = $dto->index === 1
            ? ShareholderRoleEnum::LEGAL_REPRESENTATIVE
            : ShareholderRoleEnum::SHAREHOLDER;

        return Shareholder::create([
            'registration_id' => $registration->id,
            'name' => $dto->name,
            'nationality' => $dto->nationality,
            'email' => $dto->email,
            'participation_percentage' => $dto->participationPercentage,
            'role' => $role,
            'is_married' => $dto->isMarried,
            'gender' => $dto->gender,
            'birthdate' => $dto->birthdate,
            'birthplace' => $dto->birthplace,
            'civil_status' => $dto->civilStatus,
            'phone' => $dto->phone,
            'phone_country_code' => $dto->phoneCountryCode,
            'tax_id' => $dto->taxId,
            // China captures the passport number, so keep it. When absent it stays
            // null and AnalyzeDocumentJob still extracts it from the passport image.
            'passport_number' => $dto->passportNumber,
        ]);
    }

    /**
     * Create Document metadata records for each file in the submission package.
     *
     * Skips creation if a Document with the same name already exists to avoid
     * duplicates on redelivery. Google Drive fields remain null until the notary
     * team uploads the physical file via the dashboard.
     *
     * @param  Registration  $registration  Target registration.
     * @param  list<SingapurFileDTO>  $files  File entries from the submission.
     */
    private function createDocumentMetadata(Registration $registration, array $files): void
    {
        foreach ($files as $file) {
            $this->createDocumentIfNotExists($registration, $file);
        }
    }

    /**
     * Persist a Document record for an incoming file entry.
     *
     * When the file carries base64 content it is decoded and stored in the
     * configured filesystem (R2 in production, local in development). The
     * storage path is saved in storage_path for retrieval from the dashboard.
     *
     * Skips creation if a Document with the same relay_name already exists to
     * remain idempotent on redelivery.
     *
     * @param  Registration  $registration  Parent registration.
     * @param  SingapurFileDTO  $file  File DTO from the webhook payload.
     */
    private function createDocumentIfNotExists(Registration $registration, SingapurFileDTO $file): void
    {
        $alreadyExists = Document::where('registration_id', $registration->id)
            ->where('name', $file->relayName)
            ->exists();

        if ($alreadyExists) {
            return;
        }

        // Store the file in R2 (or local disk in development) when content is provided.
        $storagePath = null;

        if ($file->hasContent()) {
            $storagePath = $this->storeFileFromBase64($registration, $file);
        }

        Document::create([
            'registration_id' => $registration->id,
            'type' => $file->documentType(),
            'name' => $file->relayName,
            'storage_path' => $storagePath,
            'google_drive_file_id' => null,
            'google_drive_url' => null,
            'stage' => RegistrationStageEnum::DATA_RECEIVED,
            // Link KYC documents to their shareholder via 1-based relay index.
            'shareholder_index' => $file->shareholderIndex(),
        ]);
    }

    /**
     * Decode base64 file content and persist it to the configured storage disk.
     *
     * Files are stored under documents/{registration_id}/{field}_{originalName}
     * to avoid collisions between shareholders sharing the same document type.
     *
     * @param  Registration  $registration  Parent registration for the path prefix.
     * @param  SingapurFileDTO  $file  File DTO carrying the base64 content.
     * @return string Storage path where the file was saved.
     */
    private function storeFileFromBase64(Registration $registration, SingapurFileDTO $file): string
    {
        $binaryContent = base64_decode($file->content, strict: true);

        if ($binaryContent === false) {
            Log::warning('Failed to decode base64 content for document — skipping storage.', [
                'registration_id' => $registration->id,
                'field' => $file->field,
                'relay_name' => $file->relayName,
            ]);

            return "pending/{$registration->id}/{$file->field}_{$file->originalName}";
        }

        // Reject files that are too large or of a disallowed type. The declared
        // content_type is advisory, but the decoded length is authoritative, so it
        // is the primary guard against storage abuse. The document row is still
        // created (see caller) so the notary team can see the file was rejected.
        if (strlen($binaryContent) > self::MAX_FILE_BYTES
            || ! in_array($file->contentType, self::ALLOWED_MIME_TYPES, strict: true)) {
            Log::warning('Rejected webhook document — exceeds size limit or disallowed MIME type.', [
                'registration_id' => $registration->id,
                'field' => $file->field,
                'relay_name' => $file->relayName,
                'content_type' => $file->contentType,
                'size' => strlen($binaryContent),
            ]);

            return "rejected/{$registration->id}/{$file->field}_{$file->originalName}";
        }

        $path = "documents/{$registration->id}/{$file->field}_{$file->originalName}";

        Storage::put($path, $binaryContent);

        Log::info('Document stored from webhook payload.', [
            'registration_id' => $registration->id,
            'path' => $path,
            'size' => strlen($binaryContent),
        ]);

        return $path;
    }
}
