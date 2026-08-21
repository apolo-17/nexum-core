<?php

declare(strict_types=1);

namespace App\Services\Registration;

use App\Enums\DocumentTypeEnum;
use App\Enums\RegistrationStageEnum;
use App\Enums\RegistrationStatusEnum;
use App\Models\Registration;
use Illuminate\Support\Facades\Log;

/**
 * Decides when a company is fully operative ("Empresa operativa") and advances it to the
 * final stage. A company is complete once it has all three SAT deliverables:
 *
 *   1. RFC de la persona moral.
 *   2. Constancia de Situación Fiscal (CSF) subida, con domicilio fiscal capturado.
 *   3. e.firma de la empresa resguardada (cer + key + contraseña).
 *
 * Best-effort: any failure to advance is logged and skipped, never thrown, so it can be
 * called safely from the request path right after a milestone is reached.
 */
class RegistrationCompletionService
{
    public function __construct(
        private readonly StageTransitionService $stages,
    ) {}

    /**
     * True when the company has RFC, CSF (con domicilio) y e.firma resguardada.
     */
    public function isComplete(Registration $registration): bool
    {
        $registration = $registration->fresh();

        $hasRfc = filled($registration->rfc);

        $hasCsfWithAddress = filled($registration->fiscal_postal_code)
            && $registration->documents()->where('type', DocumentTypeEnum::CSF->value)->exists();

        $hasEfirma = filled($registration->company_fiel_cer_path)
            && filled($registration->company_fiel_key_path)
            && filled($registration->company_fiel_password);

        return $hasRfc && $hasCsfWithAddress && $hasEfirma;
    }

    /**
     * Marca la empresa como operativa (etapa final) si ya cumple los tres entregables.
     */
    public function evaluate(?Registration $registration): void
    {
        if ($registration === null) {
            return;
        }

        $registration = $registration->fresh();

        if ($registration->status !== RegistrationStatusEnum::ACTIVE
            || $registration->stage === RegistrationStageEnum::COMPLETED
            || ! $this->isComplete($registration)) {
            return;
        }

        try {
            $this->stages->jumpTo(
                $registration,
                RegistrationStageEnum::COMPLETED,
                null,
                'Avance automático: RFC + CSF + e.firma completos. Empresa operativa.',
            );
        } catch (\Throwable $exception) {
            Log::warning('RegistrationCompletionService: could not mark the company as operative.', [
                'registration_id' => $registration->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
