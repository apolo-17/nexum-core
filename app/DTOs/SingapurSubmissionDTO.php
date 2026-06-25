<?php

namespace App\DTOs;

/**
 * Represents the fully parsed content of a Singapur relay submission package.
 *
 * Built from the submission.json file extracted from the relay ZIP download.
 * Acts as the authoritative transfer object between SingapurRelayService
 * and RegistrationUpsertService so neither service depends on raw arrays.
 */
readonly class SingapurSubmissionDTO
{
    /**
     * @param  string  $id  Submission UUID from the relay (maps to singapur_package_id).
     * @param  string  $registrationNumber  Zero-padded sequence number (e.g., '000001') — maps to singapur_client_code.
     * @param  string  $companyFolderName  Full folder name (e.g., '000001_NOVA CONSULTORA EMPRESARIAL').
     * @param  string  $companyName  Proposed company denomination (e.g., 'NOVA CONSULTORÍA EMPRESARIAL').
     * @param  string  $companyType  Type code as sent by relay: 'sa', 'srl', 'sapi'.
     * @param  string  $language  Form language code (e.g., 'zh').
     * @param  string|null  $companyObject  Corporate purpose / objeto social. Required for the acta constitutiva.
     * @param  float|null  $capitalSocial  Total share capital in MXN. Defaults to the minimum legal amount if null.
     * @param  list<SingapurShareholderDTO>  $shareholders  Parsed shareholders ordered by their relay index.
     * @param  list<SingapurFileDTO>  $files  File metadata entries from the submission package.
     * @param  SingapurFileDTO|null  $incorporationDeed  Pre-rendered acta sent by China (top-level `incorporation_deed`), already identity-verified and data-extracted on their side. Null when not provided.
     */
    public function __construct(
        public string $id,
        public string $registrationNumber,
        public string $companyFolderName,
        public string $companyName,
        public string $companyType,
        public string $language,
        public ?string $companyObject,
        public ?float $capitalSocial,
        public array $shareholders,
        public array $files,
        public ?SingapurFileDTO $incorporationDeed = null,
    ) {}

    /**
     * Resolve the display-ready company type string from the relay code.
     *
     * Unknown codes are returned as-is so data is never silently lost.
     */
    public function resolvedCompanyType(): string
    {
        return match (strtolower($this->companyType)) {
            'sa' => 'SA de CV',
            'srl' => 'SRL de CV',
            'sapi' => 'SAPI de CV',
            default => strtoupper($this->companyType),
        };
    }
}
