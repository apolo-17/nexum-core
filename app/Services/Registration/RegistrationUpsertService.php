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
            $this->upsertInitialLegalName($registration, $dto->companyName);
            $this->syncShareholders($registration, $dto->shareholders);
            $this->createDocumentMetadata($registration, $dto->files);

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
                'stage' => RegistrationStageEnum::DATA_RECEIVED,
                'status' => RegistrationStatusEnum::ACTIVE,
            ],
        );

        // Refresh relay metadata on subsequent deliveries without touching stage/status.
        if (! $registration->wasRecentlyCreated) {
            $registration->update([
                'singapur_package_id' => $dto->id,
                'singapur_folder_name' => $dto->companyFolderName,
                'company_type' => $dto->resolvedCompanyType(),
            ]);
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
     */
    private function upsertInitialLegalName(Registration $registration, string $companyName): void
    {
        if (blank($companyName)) {
            return;
        }

        /** @var LegalName|null $existing */
        $existing = $registration->legalNames()->where('priority', 1)->first();

        if ($existing === null) {
            LegalName::create([
                'registration_id' => $registration->id,
                'name' => $companyName,
                'priority' => 1,
                'status' => LegalNameStatusEnum::WAIT,
            ]);

            return;
        }

        // Only update if the notary team has not advanced it to a locked state.
        if ($existing->isEditable()) {
            $existing->update(['name' => $companyName]);
        }
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
            // Passport number is not available from the relay; it is filled
            // manually by the notary team after reviewing the passport document.
            'passport_number' => null,
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
