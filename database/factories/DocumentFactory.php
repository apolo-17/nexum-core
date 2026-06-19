<?php

namespace Database\Factories;

use App\Enums\DocumentTypeEnum;
use App\Enums\RegistrationStageEnum;
use App\Models\Document;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for creating Document model instances in tests.
 *
 * Defaults to a manual (non-relay) document with no Drive link or relay path.
 * Use state methods to configure relay documents or verified documents.
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
            'registration_id'      => Registration::factory(),
            'type'                 => DocumentTypeEnum::OTHER,
            'name'                 => fake()->word() . '.pdf',
            'relay_zip_path'       => null,
            'google_drive_file_id' => null,
            'google_drive_url'     => null,
            'stage'                => RegistrationStageEnum::DATA_RECEIVED,
            'uploaded_by'          => null,
            'verified_at'          => null,
            'verified_by'          => null,
        ];
    }

    /**
     * Configure the document as a relay KYC file with its ZIP entry path.
     *
     * @param  string  $clientCode       Six-digit client code (e.g. '000001').
     * @param  int     $shareholderIndex Position in the KYC ZIP (1-based).
     * @param  string  $field            Relay field name (e.g. 'naturalTaxCertificate1').
     * @return static
     */
    public function fromRelay(string $clientCode, int $shareholderIndex, string $field): static
    {
        $relayName = "{$clientCode}__{$field}__tax.pdf";

        return $this->state([
            'type'           => DocumentTypeEnum::CSF,
            'name'           => $relayName,
            'relay_zip_path' => "KYC/shareholder_{$shareholderIndex}/{$relayName}",
        ]);
    }

    /**
     * Configure the document as verified.
     *
     * @return static
     */
    public function verified(): static
    {
        return $this->state([
            'verified_at' => now()->subDays(fake()->numberBetween(1, 30)),
        ]);
    }

    /**
     * Configure the document with a Google Drive link (manual upload).
     *
     * @return static
     */
    public function onDrive(): static
    {
        $fileId = '1' . fake()->regexify('[A-Za-z0-9_-]{32}');

        return $this->state([
            'google_drive_file_id' => $fileId,
            'google_drive_url'     => "https://drive.google.com/file/d/{$fileId}/view",
        ]);
    }
}
