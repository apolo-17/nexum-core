<?php

namespace App\Enums;

/**
 * Defines the accepted document types that can be attached to a registration expedient.
 */
enum DocumentTypeEnum: string
{
    case PASSPORT           = 'passport';
    case VISA               = 'visa';
    case POWER_OF_ATTORNEY  = 'power_of_attorney';
    case INCORPORATION_ACT  = 'incorporation_act';
    case CSF                = 'csf';
    case BANK_PROOF         = 'bank_proof';
    case RFC_DOCUMENT       = 'rfc_document';
    case EFIRMA             = 'efirma';
    case OTHER              = 'other';

    /**
     * Return a human-readable Spanish label for display in the dashboard.
     *
     * @return string
     */
    public function label(): string
    {
        return match($this) {
            self::PASSPORT          => 'Pasaporte',
            self::VISA              => 'Visa',
            self::POWER_OF_ATTORNEY => 'Poder notarial',
            self::INCORPORATION_ACT => 'Acta constitutiva',
            self::CSF               => 'Constancia de Situación Fiscal',
            self::BANK_PROOF        => 'Comprobante bancario',
            self::RFC_DOCUMENT      => 'Documento RFC',
            self::EFIRMA            => 'e.firma',
            self::OTHER             => 'Otro',
        };
    }
}
