<?php

declare(strict_types=1);

namespace App\Services\Registration;

use App\Enums\DocumentTypeEnum;
use App\Enums\RegistrationStageEnum;
use App\Models\Document;
use App\Models\Registration;
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
        private readonly StageTransitionService $stages,
        private readonly RegistrationCompletionService $completion,
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

        // Con el RFC ya en el expediente, el hito "Registro SAT" está cumplido: avanza la etapa.
        if (filled($registration->fresh()->rfc)) {
            $this->advanceToSatRegistration($registration);
        }

        // Si con este CSF (RFC + domicilio) ya están los tres entregables, la empresa es operativa.
        $this->completion->evaluate($registration);
    }

    /**
     * Avanza el expediente a "Registro SAT" (forward-only, sin romper el flujo si falla).
     */
    private function advanceToSatRegistration(Registration $registration): void
    {
        try {
            $this->stages->jumpTo(
                $registration->fresh(),
                RegistrationStageEnum::SAT_REGISTRATION,
                null,
                'Avance automático: RFC obtenido (CSF procesado).',
            );
        } catch (\Throwable $exception) {
            Log::warning('CsfExtractionService: could not advance the registration stage.', [
                'registration_id' => $registration->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
