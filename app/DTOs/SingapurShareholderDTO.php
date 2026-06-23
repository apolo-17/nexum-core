<?php

namespace App\DTOs;

/**
 * Represents a single shareholder parsed from a Singapur relay submission.
 *
 * All shareholder fields come from the `fields` section of submission.json
 * using the pattern `natural{Field}{index}` for natural persons.
 *
 * Identity fields (gender, birthdate, birthplace) are optional: when provided
 * by the relay they are persisted immediately; when absent they are extracted
 * automatically by AnalyzeDocumentJob once the passport is approved.
 */
readonly class SingapurShareholderDTO
{
    /**
     * @param  int  $index  1-based index as received in the submission.
     * @param  string  $type  Shareholder type: 'natural' or 'juridica'.
     * @param  string  $name  Full legal name.
     * @param  string  $nationality  Country of nationality as received (e.g., 'china').
     * @param  string  $email  Email address.
     * @param  float  $participationPercentage  Ownership share as a percentage.
     * @param  bool  $isMarried  Whether the shareholder is married.
     * @param  string|null  $gender  'M' or 'F'. Extracted from passport if not provided.
     * @param  string|null  $birthdate  ISO-8601 date (YYYY-MM-DD). Extracted from passport if not provided.
     * @param  string|null  $birthplace  City / country of birth. Extracted from passport if not provided.
     * @param  string|null  $civilStatus  soltero | casado | divorciado | viudo. Derived from isMarried if not provided.
     * @param  string|null  $phone  Phone number. Used for DocuSign SMS verification.
     * @param  string|null  $phoneCountryCode  E.164 dialling code, e.g. '+86'.
     * @param  string|null  $taxId  Foreign tax ID (NIF, TIN, etc.). Not applicable for Chinese nationals.
     */
    public function __construct(
        public int $index,
        public string $type,
        public string $name,
        public string $nationality,
        public string $email,
        public float $participationPercentage,
        public bool $isMarried,
        // Optional identity fields — absent in the initial webhook, filled later
        // from the KYC documents (AI extraction) or manually by the notary team.
        public ?string $gender = null,
        public ?string $birthdate = null,
        public ?string $birthplace = null,
        public ?string $civilStatus = null,
        public ?string $phone = null,
        public ?string $phoneCountryCode = null,
        public ?string $taxId = null,
    ) {}
}
