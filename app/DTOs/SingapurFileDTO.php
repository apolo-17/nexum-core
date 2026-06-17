<?php

namespace App\DTOs;

use App\Enums\DocumentTypeEnum;

/**
 * Represents a single file entry from a Singapur relay submission package.
 *
 * Carries the file metadata received in submission.json under the `files` array.
 * The `field` name encodes both the document type and the shareholder index
 * (e.g., naturalTaxCertificate1 → CSF, shareholder index 1).
 */
readonly class SingapurFileDTO
{
    /**
     * @param  string  $field         Form field name (e.g., naturalTaxCertificate1).
     * @param  string  $originalName  File name as uploaded by the client.
     * @param  string  $storedName    Internal stored name on the relay server.
     * @param  string  $relayName     Human-readable name used as the document label.
     * @param  string  $contentType   MIME type of the file.
     * @param  int     $size          File size in bytes.
     */
    public function __construct(
        public string $field,
        public string $originalName,
        public string $storedName,
        public string $relayName,
        public string $contentType,
        public int $size,
    ) {}

    /**
     * Build a DTO from a raw submission.json file array entry.
     *
     * @param  array<string, mixed>  $data  Single entry from submission.json `files[]`.
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            field:        $data['field'],
            originalName: $data['original_name'],
            storedName:   $data['stored_name'],
            relayName:    $data['relay_name'],
            contentType:  $data['content_type'],
            size:         (int) $data['size'],
        );
    }

    /**
     * Extract the 1-based shareholder index embedded in the field name.
     *
     * Returns null if the field is not shareholder-specific.
     *
     * @return int|null
     */
    public function shareholderIndex(): ?int
    {
        if (preg_match('/(\d+)$/', $this->field, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Map the relay field name prefix to the closest internal DocumentTypeEnum.
     *
     * Fields not matching a known prefix default to OTHER so they are still
     * persisted and visible to the notary team for manual classification.
     *
     * @return DocumentTypeEnum
     */
    public function documentType(): DocumentTypeEnum
    {
        // Strip the trailing shareholder index to get the field prefix.
        $prefix = rtrim($this->field, '0123456789');

        return match ($prefix) {
            'naturalPassport'           => DocumentTypeEnum::PASSPORT,
            'naturalTaxCertificate'     => DocumentTypeEnum::CSF,
            'naturalBankProof'          => DocumentTypeEnum::BANK_PROOF,
            'naturalVisa'               => DocumentTypeEnum::VISA,
            default                     => DocumentTypeEnum::OTHER,
        };
    }
}
