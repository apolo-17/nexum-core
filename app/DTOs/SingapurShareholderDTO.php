<?php

namespace App\DTOs;

/**
 * Represents a single shareholder parsed from a Singapur relay submission.
 *
 * All shareholder fields come from the `fields` section of submission.json
 * using the pattern `natural{Field}{index}` for natural persons.
 */
readonly class SingapurShareholderDTO
{
    /**
     * @param  int     $index                   1-based index as received in the submission.
     * @param  string  $type                    Shareholder type: 'natural' or 'juridica'.
     * @param  string  $name                    Full legal name.
     * @param  string  $nationality             Country of nationality as received (e.g., 'china').
     * @param  string  $email                   Email address (may contain city names in legacy submissions).
     * @param  float   $participationPercentage Ownership share as a percentage.
     * @param  bool    $isMarried               Whether the shareholder is married.
     */
    public function __construct(
        public int $index,
        public string $type,
        public string $name,
        public string $nationality,
        public string $email,
        public float $participationPercentage,
        public bool $isMarried,
    ) {}
}
