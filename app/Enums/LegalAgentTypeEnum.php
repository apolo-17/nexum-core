<?php

namespace App\Enums;

/**
 * Type of legal agent assignable to an acta constitutiva.
 *
 * Foreign-owned companies (the Chinese clients) require an acta with a designated
 * legal representative and a commissary (comisario). These are kept in a reusable
 * catalog and assigned manually to each acta by the notary team.
 */
enum LegalAgentTypeEnum: string
{
    case LEGAL_REPRESENTATIVE = 'legal_representative';
    case COMMISSARY = 'commissary';

    /**
     * Return a human-readable Spanish label for display in the dashboard.
     */
    public function label(): string
    {
        return match ($this) {
            self::LEGAL_REPRESENTATIVE => 'Representante legal',
            self::COMMISSARY => 'Comisario',
        };
    }

    /**
     * Return a Filament badge color for this type.
     */
    public function color(): string
    {
        return match ($this) {
            self::LEGAL_REPRESENTATIVE => 'info',
            self::COMMISSARY => 'warning',
        };
    }

    /**
     * Build a value => label map for use in Filament select/filter options.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }
}
