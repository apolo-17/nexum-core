<?php

declare(strict_types=1);

namespace App\Services\Registration;

use App\Enums\DocumentTypeEnum;
use App\Models\Document;
use App\Models\Registration;

/**
 * Checks that a registration holds everything the acta render needs, returning a list of
 * human-readable issues (empty when the render can be built). Pure and DB-only: safe to run
 * repeatedly and easy to test. The messages are shown to the team in the "render could not
 * be completed" alert so they know exactly what to add.
 */
class ActaCompletenessValidator
{
    /**
     * @return list<string> Blocking issues (Spanish, for the alert); empty when renderable.
     */
    public function validate(Registration $registration): array
    {
        $registration->loadMissing(['primaryLegalName', 'shareholders', 'documents', 'legalRepresentatives']);
        $issues = [];

        $this->checkDenomination($registration, $issues);
        $this->checkCompany($registration, $issues);
        $this->checkShareholders($registration, $issues);
        $this->checkApoderados($registration, $issues);

        return $issues;
    }

    /**
     * @param  list<string>  $issues
     */
    private function checkDenomination(Registration $registration, array &$issues): void
    {
        $denomination = $registration->primaryLegalName;

        if ($denomination === null || blank($denomination->name)) {
            $issues[] = 'Falta la denominación aprobada.';

            return;
        }

        if (blank($denomination->clave_unica_denominacion)) {
            $issues[] = 'La denominación no tiene folio (CUD) de la SE.';
        }

        if ($denomination->authorization_timestamp === null) {
            $issues[] = 'La denominación no tiene fecha de autorización.';
        }
    }

    /**
     * @param  list<string>  $issues
     */
    private function checkCompany(Registration $registration, array &$issues): void
    {
        if (blank($registration->capital_social)) {
            $issues[] = 'Falta el capital social.';
        }

        // El objeto social ya no se valida: es el mismo boilerplate para todas las empresas
        // (default en SingapurSubmissionParser / fijo en la plantilla), nunca falta de verdad.
    }

    /**
     * @param  list<string>  $issues
     */
    private function checkShareholders(Registration $registration, array &$issues): void
    {
        $shareholders = $registration->shareholders->values();

        if ($shareholders->isEmpty()) {
            $issues[] = 'No hay socios registrados.';

            return;
        }

        foreach ($shareholders as $position => $shareholder) {
            $index = $position + 1;
            $label = filled($shareholder->name) ? $shareholder->name : "socio {$index}";

            if (blank($shareholder->name)) {
                $issues[] = "El socio {$index} no tiene nombre.";
            }

            if ((float) $shareholder->participation_percentage <= 0) {
                $issues[] = "El socio «{$label}» no tiene porcentaje de participación.";
            }

            if (blank($shareholder->nationality)) {
                $issues[] = "El socio «{$label}» no tiene nacionalidad.";
            }

            if (blank($shareholder->passport_number)) {
                $issues[] = "El socio «{$label}» no tiene número de pasaporte.";
            }

            if (! $this->hasPassportDocument($registration, $index)) {
                $issues[] = "El socio «{$label}» no tiene su pasaporte en la documentación.";
            }
        }
    }

    /**
     * @param  list<string>  $issues
     */
    private function checkApoderados(Registration $registration, array &$issues): void
    {
        $apoderados = $registration->legalRepresentatives;

        if ($apoderados->count() < ApoderadoAssignmentService::MIN_APODERADOS) {
            $issues[] = 'El acta necesita al menos '.ApoderadoAssignmentService::MIN_APODERADOS
                ." apoderados; tiene {$apoderados->count()}.";
        }

        foreach ($apoderados as $apoderado) {
            if (blank($apoderado->rfc)) {
                $issues[] = "El apoderado «{$apoderado->name}» no tiene RFC.";
            }
        }
    }

    private function hasPassportDocument(Registration $registration, int $shareholderIndex): bool
    {
        return $registration->documents->contains(
            fn (Document $document): bool => in_array(
                $document->type,
                [DocumentTypeEnum::PASSPORT, DocumentTypeEnum::KYC_SPOUSE_PASSPORT],
                true,
            )
                && (int) $document->shareholder_index === $shareholderIndex
                && filled($document->storage_path),
        );
    }
}
