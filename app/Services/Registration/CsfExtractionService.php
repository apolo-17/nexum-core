<?php

declare(strict_types=1);

namespace App\Services\Registration;

use App\Enums\DocumentTypeEnum;
use App\Models\Document;
use App\Services\Document\DocumentAnalysisService;
use Illuminate\Support\Facades\Log;

/**
 * Reads a company's Constancia de Situación Fiscal (CSF) with Claude vision and fills the
 * registration's RFC and fiscal-address fields from it.
 *
 * The RFC is only written when the registration does not already have one (a confirmed RFC
 * is never overwritten). The fiscal_* fields are filled from the CSF's "Datos de Ubicación"
 * block. Best-effort: any extraction failure is logged and skipped, never thrown.
 */
class CsfExtractionService
{
    /** CSF field => registration column. */
    private const FISCAL_FIELDS = [
        'fiscal_street',
        'fiscal_ext_number',
        'fiscal_int_number',
        'fiscal_neighborhood',
        'fiscal_municipality',
        'fiscal_state',
        'fiscal_postal_code',
    ];

    public function __construct(
        private readonly DocumentAnalysisService $analysis,
    ) {}

    /**
     * Extract the CSF and apply the RFC + fiscal address to its registration.
     */
    public function applyToRegistration(Document $csf): void
    {
        if ($csf->type !== DocumentTypeEnum::CSF || $csf->registration_id === null || blank($csf->storage_path)) {
            return;
        }

        try {
            $fields = $this->analysis->extractFromDocument($csf);
        } catch (\Throwable $exception) {
            Log::warning('CsfExtractionService: could not read the CSF.', [
                'document_id' => $csf->id,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        $registration = $csf->registration;
        $updates = [];

        // RFC: only fill when the registration has none — never overwrite a confirmed RFC.
        $rfc = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', (string) ($fields['rfc'] ?? '')));
        if ($rfc !== '' && blank($registration->rfc)) {
            $updates['rfc'] = $rfc;
        }

        foreach (self::FISCAL_FIELDS as $field) {
            if (filled($fields[$field] ?? null)) {
                $updates[$field] = trim((string) $fields[$field]);
            }
        }

        if ($updates !== []) {
            $registration->update($updates);
        }
    }
}
