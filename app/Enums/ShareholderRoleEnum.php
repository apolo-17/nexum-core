<?php

namespace App\Enums;

/**
 * Defines the legal roles a shareholder can hold within the company being incorporated.
 */
enum ShareholderRoleEnum: string
{
    case LEGAL_REPRESENTATIVE = 'legal_representative';
    case SHAREHOLDER          = 'shareholder';
    case COMMISSARY           = 'commissary';

    /**
     * Return a human-readable Spanish label for display in the dashboard.
     *
     * @return string
     */
    public function label(): string
    {
        return match($this) {
            self::LEGAL_REPRESENTATIVE => 'Representante legal',
            self::SHAREHOLDER          => 'Socio',
            self::COMMISSARY           => 'Comisario',
        };
    }
}
