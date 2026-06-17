<?php

namespace App\Enums;

/**
 * Represents the eight sequential stages of a company registration process.
 *
 * Each case maps to a specific phase the notary team tracks in the dashboard.
 * Transitions are recorded in stage_transitions and enforced by the state machine service.
 */
enum RegistrationStageEnum: string
{
    case DATA_RECEIVED        = 'data_received';
    case IDENTITY_VALIDATION  = 'identity_validation';
    case LEGAL_NAME           = 'legal_name';
    case INCORPORATION        = 'incorporation';
    case BANK_ACCOUNT         = 'bank_account';
    case SAT_REGISTRATION     = 'sat_registration';
    case EFIRMA_APPOINTMENT   = 'efirma_appointment';
    case COMPLETED            = 'completed';

    /**
     * Return a human-readable Spanish label for display in the dashboard.
     *
     * @return string
     */
    public function label(): string
    {
        return match($this) {
            self::DATA_RECEIVED       => 'Datos recibidos',
            self::IDENTITY_VALIDATION => 'Validación de identidad',
            self::LEGAL_NAME          => 'Denominación social',
            self::INCORPORATION       => 'Constitución de empresa',
            self::BANK_ACCOUNT        => 'Apertura de cuenta bancaria',
            self::SAT_REGISTRATION    => 'Alta en el SAT',
            self::EFIRMA_APPOINTMENT  => 'Cita e.firma SAT',
            self::COMPLETED           => 'Empresa operativa',
        };
    }

    /**
     * Return the ordered list of all stages for state machine validation.
     *
     * @return array<int, self>
     */
    public static function orderedStages(): array
    {
        return [
            self::DATA_RECEIVED,
            self::IDENTITY_VALIDATION,
            self::LEGAL_NAME,
            self::INCORPORATION,
            self::BANK_ACCOUNT,
            self::SAT_REGISTRATION,
            self::EFIRMA_APPOINTMENT,
            self::COMPLETED,
        ];
    }
}
