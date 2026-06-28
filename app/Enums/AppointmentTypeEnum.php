<?php

namespace App\Enums;

/**
 * The two SAT appointments every company needs during incorporation.
 *
 * Each registration requires exactly these two appointments, in order:
 *   1. RFC  — register the company before the SAT and obtain its RFC.
 *   2. FIEL — issue the company's e.firma (advanced electronic signature).
 *
 * The bot (separate service) will request and schedule these; for now they are
 * captured manually. A single appointment type may have several records over time
 * when a previous attempt is rejected or no-showed and must be rescheduled.
 */
enum AppointmentTypeEnum: string
{
    /**
     * Appointment to register the company and obtain its RFC.
     */
    case RFC = 'rfc';

    /**
     * Appointment to issue the company's e.firma (FIEL).
     */
    case FIEL = 'fiel';

    /**
     * Return a human-readable Spanish label for display in the dashboard.
     */
    public function label(): string
    {
        return match ($this) {
            self::RFC => 'Cita RFC',
            self::FIEL => 'Cita e.firma (FIEL)',
        };
    }

    /**
     * Return the badge color for this appointment type.
     */
    public function color(): string
    {
        return match ($this) {
            self::RFC => 'info',
            self::FIEL => 'success',
        };
    }

    /**
     * Return a value => label map for select inputs and filters.
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
