<?php

namespace App\Enums;

/**
 * Lifecycle of a SAT appointment in Nexum.
 *
 * The soldado is "formed" (queued) at the SAT portal MANUALLY by the team; from there
 * a status monitor (the nexum-citas-sat bot) checks the formed appointments every few
 * hours and, when the SAT assigns a slot, records the acuse + date/time/branch.
 *
 *   pending_forming → (human forms at SAT) → formed → (bot reviews) → scheduled
 *                                                          └→ rejected / no_show
 */
enum AppointmentStatusEnum: string
{
    /**
     * Created in Nexum; not yet formed at the SAT portal.
     */
    case PENDING_FORMING = 'pending_forming';

    /**
     * Formed manually at the SAT (in the virtual queue); awaiting the assigned slot.
     */
    case FORMED = 'formed';

    /**
     * The SAT assigned a date/time/branch. Acuse available.
     */
    case SCHEDULED = 'scheduled';

    /**
     * The SAT rejected it (a new appointment must be formed).
     */
    case REJECTED = 'rejected';

    /**
     * The soldado did not attend the assigned appointment.
     */
    case NO_SHOW = 'no_show';

    /**
     * Human-readable Spanish label for the dashboard.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING_FORMING => 'Por formar',
            self::FORMED => 'Formada (por revisar)',
            self::SCHEDULED => 'Agendada',
            self::REJECTED => 'Rechazada',
            self::NO_SHOW => 'No asistió',
        };
    }

    /**
     * Badge color (Filament palette).
     */
    public function color(): string
    {
        return match ($this) {
            self::PENDING_FORMING => 'gray',
            self::FORMED => 'warning',
            self::SCHEDULED => 'success',
            self::REJECTED => 'danger',
            self::NO_SHOW => 'danger',
        };
    }

    /**
     * True when the appointment reached a terminal state.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::SCHEDULED, self::REJECTED, self::NO_SHOW], true);
    }

    /**
     * value => label map for selects and filters.
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
