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
     * @return SingapurSubmissionDTO
     *
     * @throws RuntimeException When required top-level fields are missing.
     */
    public function parse(array $data): SingapurSubmissionDTO
    {
        $this->assertRequired($data, ['id', 'registration_number', 'company_folder_name', 'fields']);

        $fields = $data['fields'];
        $shareholderCount = (int) ($fields['shareholderCount'] ?? 0);

        return new SingapurSubmissionDTO(
            id:                 $data['id'],
            registrationNumber: $data['registration_number'],
            companyFolderName:  $data['company_folder_name'],
            companyName:        $fields['companyName'] ?? '',
            companyType:        $fields['companyType'] ?? '',
            language:           $fields['_language'] ?? 'zh',
            shareholders:       $this->parseShareholders($fields, $shareholderCount),
            files:              $this->parseFiles($data['files'] ?? []),
        );
    }

    /**
     * Build shareholder DTOs by iterating the expected 1-based index range.
     *
     * @param  array<string, mixed>  $fields           The `fields` section of submission.json.
     * @param  int                   $shareholderCount  Number of shareholders declared.
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
                index:                  $i,
                type:                   $type,
                name:                   $fields["naturalShareholderName{$i}"] ?? $fields["juridicaShareholderName{$i}"] ?? "Shareholder {$i}",
                nationality:            $fields["naturalNationality{$i}"] ?? $fields["naturalOtherNationality{$i}"] ?? '',
                email:                  $fields["naturalShareholderEmail{$i}"] ?? '',
                participationPercentage: (float) ($fields["naturalSharePercentage{$i}"] ?? 0),
                isMarried:              strtolower($fields["naturalMarried{$i}"] ?? 'no') === 'yes',
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
     * @param  array<string, mixed>  $data      Data to validate.
     * @param  list<string>          $required  Required key names.
     * @return void
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
