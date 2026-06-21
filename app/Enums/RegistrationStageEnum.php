<?php

namespace App\Enums;

/**
 * Represents the nine sequential stages of a company registration process.
 *
 * Order reflects the real notary workflow:
 *   Data → Identity → Legal name → Partner signatures (DocuSign) →
 *   Incorporation → Tax address → SAT registration → e.firma → Completed
 *
 * Stages advance sequentially via StageTransitionService::advance().
 * No hard gates — the notary team confirms each stage when ready.
 */
enum RegistrationStageEnum: string
{
    case DATA_RECEIVED = 'data_received';
    case IDENTITY_VALIDATION = 'identity_validation';
    case LEGAL_NAME = 'legal_name';
    case PARTNER_SIGNATURE = 'partner_signature';
    case INCORPORATION = 'incorporation';
    case TAX_ADDRESS = 'tax_address';
    case SAT_REGISTRATION = 'sat_registration';
    case EFIRMA_APPOINTMENT = 'efirma_appointment';
    case COMPLETED = 'completed';

    /**
     * Return a human-readable Spanish label for display in the dashboard pipeline.
     */
    public function label(): string
    {
        return match ($this) {
            self::DATA_RECEIVED => 'Datos recibidos',
            self::IDENTITY_VALIDATION => 'Validación de identidad',
            self::LEGAL_NAME => 'Denominación social',
            self::PARTNER_SIGNATURE => 'Firma de socios',
            self::INCORPORATION => 'Constitución de empresa',
            self::TAX_ADDRESS => 'Domicilio fiscal',
            self::SAT_REGISTRATION => 'Alta en el SAT',
            self::EFIRMA_APPOINTMENT => 'Cita e.firma SAT',
            self::COMPLETED => 'Empresa operativa',
        };
    }

    /**
     * Return a short label suitable for use in the compact horizontal pipeline stepper.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::DATA_RECEIVED => 'Datos',
            self::IDENTITY_VALIDATION => 'Identidad',
            self::LEGAL_NAME => 'Denominación',
            self::PARTNER_SIGNATURE => 'Firma socios',
            self::INCORPORATION => 'Constitución',
            self::TAX_ADDRESS => 'Dom. fiscal',
            self::SAT_REGISTRATION => 'Alta SAT',
            self::EFIRMA_APPOINTMENT => 'e.firma',
            self::COMPLETED => 'Operativo',
        };
    }

    /**
     * Return the ordered list of all active stages for state machine validation.
     *
     * @return array<int, self>
     */
    public static function orderedStages(): array
    {
        return [
            self::DATA_RECEIVED,
            self::IDENTITY_VALIDATION,
            self::LEGAL_NAME,
            self::PARTNER_SIGNATURE,
            self::INCORPORATION,
            self::TAX_ADDRESS,
            self::SAT_REGISTRATION,
            self::EFIRMA_APPOINTMENT,
            self::COMPLETED,
        ];
    }
}
