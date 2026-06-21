<?php

namespace Database\Factories;

use App\Enums\DocumentTypeEnum;
use App\Enums\RegistrationStageEnum;
use App\Models\Document;
use App\Models\Registration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for creating Document model instances in tests.
 *
 * Defaults to a manual (non-relay) document with no Drive link or storage path.
 * Use state methods to configure relay (webhook) documents or verified documents.
 *
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registration_id' => Registration::factory(),
            'type' => DocumentTypeEnum::OTHER,
            'name' => fake()->word().'.pdf',
            'storage_path' => null,
            'google_drive_file_id' => null,
            'google_drive_url' => null,
            'stage' => RegistrationStageEnum::DATA_RECEIVED,
            'uploaded_by' => null,
            'verified_at' => null,
            'verified_by' => null,
        ];
    }

    /**
     * Configure the document as a relay KYC file with its R2 storage path.
     *
     * Simulates a file that arrived via the Singapur webhook as base64 content
     * and was persisted to the configured storage disk (R2 in production).
     *
     * @param  string  $clientCode  Six-digit client code (e.g. '000001').
     * @param  int  $shareholderIndex  Shareholder index for path uniqueness (1-based).
     * @param  string  $field  Relay field name (e.g. 'naturalTaxCertificate1').
     */
    public function fromRelay(string $clientCode, int $shareholderIndex, string $field): static
    {
        $relayName = "{$clientCode}__{$field}__tax.pdf";

        return $this->state([
            'type' => DocumentTypeEnum::CSF,
            'name' => $relayName,
            'storage_path' => "documents/{$clientCode}/{$field}_{$relayName}",
        ]);
    }

    /**
     * Configure the document as verified.
     */
    public function verified(): static
    {
        return $this->state([
            'verified_at' => now()->subDays(fake()->numberBetween(1, 30)),
        ]);
    }

    /**
     * Configure the document with a Google Drive link (manual upload).
     */
    public function onDrive(): static
    {
        $fileId = '1'.fake()->regexify('[A-Za-z0-9_-]{32}');

        return $this->state([
            'google_drive_file_id' => $fileId,
            'google_drive_url' => "https://drive.google.com/file/d/{$fileId}/view",
        ]);
    }
}
