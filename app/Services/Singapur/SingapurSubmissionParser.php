<?php

namespace App\Services\Singapur;

use App\DTOs\SingapurFileDTO;
use App\DTOs\SingapurShareholderDTO;
use App\DTOs\SingapurSubmissionDTO;
use RuntimeException;

/**
 * Parses the decoded submission.json from a Singapur relay ZIP into typed DTOs.
 *
 * Responsible only for transforming raw array data into value objects.
 * Has no I/O dependencies and is safe to unit test without mocks.
 */
class SingapurSubmissionParser
{
    /**
     * Parse the decoded content of submission.json into a SingapurSubmissionDTO.
     *
     * @param  array<string, mixed>  $data  Decoded submission.json array.
     *
     * @throws RuntimeException When required top-level fields are missing.
     */
    public function parse(array $data): SingapurSubmissionDTO
    {
        $this->assertRequired($data, ['id', 'registration_number', 'company_folder_name', 'fields']);

        $fields = $data['fields'];
        $shareholderCount = (int) ($fields['shareholderCount'] ?? 0);

        return new SingapurSubmissionDTO(
            id: $data['id'],
            registrationNumber: $data['registration_number'],
            companyFolderName: $data['company_folder_name'],
            companyName: $fields['companyName'] ?? '',
            companyType: $fields['companyType'] ?? '',
            language: $fields['_language'] ?? 'zh',
            companyObject: $fields['companyObject'] ?? null,
            capitalSocial: isset($fields['capitalSocial']) ? (float) $fields['capitalSocial'] : null,
            shareholders: $this->parseShareholders($fields, $shareholderCount),
            files: $this->parseFiles($data['files'] ?? []),
            incorporationDeed: $this->parseIncorporationDeed($data),
        );
    }

    /**
     * Build a file DTO for the top-level `incorporation_deed` field, when present.
     *
     * China sends the pre-rendered acta as a base64 string (or an object carrying
     * `content` plus optional `content_type` / `original_name`). Returns null when
     * the field is absent or empty so the rest of the pipeline is unaffected.
     *
     * @param  array<string, mixed>  $data  Decoded submission.json array.
     */
    private function parseIncorporationDeed(array $data): ?SingapurFileDTO
    {
        $deed = $data['incorporation_deed'] ?? null;

        if (blank($deed)) {
            return null;
        }

        // Accept both a bare base64 string and a richer object form.
        $content = is_array($deed) ? ($deed['content'] ?? null) : (string) $deed;
        $contentType = is_array($deed) ? ($deed['content_type'] ?? 'application/pdf') : 'application/pdf';
        $originalName = is_array($deed) ? ($deed['original_name'] ?? 'acta_constitutiva') : 'acta_constitutiva';

        if (blank($content)) {
            return null;
        }

        $registrationNumber = $data['registration_number'] ?? '';
        $decoded = base64_decode((string) $content, strict: true);

        return new SingapurFileDTO(
            field: 'incorporationDeed',
            originalName: $originalName,
            relayName: "{$registrationNumber}__incorporationDeed__{$originalName}",
            contentType: $contentType,
            size: $decoded === false ? 0 : strlen($decoded),
            content: (string) $content,
        );
    }

    /**
     * Build shareholder DTOs by iterating the expected 1-based index range.
     *
     * @param  array<string, mixed>  $fields  The `fields` section of submission.json.
     * @param  int  $shareholderCount  Number of shareholders declared.
     * @return list<SingapurShareholderDTO>
     */
    private function parseShareholders(array $fields, int $shareholderCount): array
    {
        $shareholders = [];

        for ($i = 1; $i <= $shareholderCount; $i++) {
            $type = $fields["shareholderType{$i}"] ?? 'natural';

            // Only natural persons are supported in the initial relay contract.
            // Juridica (legal entity) shareholders are stored with a placeholder name.
            $shareholders[] = new SingapurShareholderDTO(
                index: $i,
                type: $type,
                name: $fields["naturalShareholderName{$i}"] ?? $fields["juridicaShareholderName{$i}"] ?? "Shareholder {$i}",
                nationality: $fields["naturalNationality{$i}"] ?? $fields["naturalOtherNationality{$i}"] ?? '',
                email: $fields["naturalShareholderEmail{$i}"] ?? '',
                participationPercentage: (float) ($fields["naturalSharePercentage{$i}"] ?? 0),
                isMarried: strtolower($fields["naturalMarried{$i}"] ?? 'no') === 'yes',
                gender: $fields["naturalGender{$i}"] ?? null,
                birthdate: $fields["naturalBirthdate{$i}"] ?? null,
                birthplace: $fields["naturalBirthplace{$i}"] ?? null,
                civilStatus: $fields["naturalCivilStatus{$i}"] ?? null,
                phone: $fields["naturalPhone{$i}"] ?? null,
                phoneCountryCode: $fields["naturalPhoneCountryCode{$i}"] ?? null,
                taxId: $fields["naturalTaxId{$i}"] ?? null,
            );
        }

        return $shareholders;
    }

    /**
     * Build file DTOs from the `files` array of submission.json.
     *
     * @param  array<int, array<string, mixed>>  $files  Raw file entries.
     * @return list<SingapurFileDTO>
     */
    private function parseFiles(array $files): array
    {
        return array_values(
            array_map(
                static fn (array $file) => SingapurFileDTO::fromArray($file),
                $files,
            )
        );
    }

    /**
     * Assert that required keys are present in the data array.
     *
     * @param  array<string, mixed>  $data  Data to validate.
     * @param  list<string>  $required  Required key names.
     *
     * @throws RuntimeException When any required key is missing.
     */
    private function assertRequired(array $data, array $required): void
    {
        foreach ($required as $key) {
            if (! array_key_exists($key, $data)) {
                throw new RuntimeException("Singapur submission.json is missing required field: {$key}");
            }
        }
    }
}
