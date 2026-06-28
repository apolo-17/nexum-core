<?php

namespace App\Enums;

/**
 * Represents the possible states of a client's e.firma SAT appointment.
 *
 * Transitions are driven by the admin confirming the outcome of each appointment.
 * The bot schedules the actual appointment date; this enum only tracks the result.
 */
enum EfirmaAppointmentStatusEnum: string
{
    /**
     * Appointment has been requested to the SAT bot but not yet confirmed.
     */
    case PENDING_SCHEDULING = 'pending_scheduling';

    /**
     * The SAT bot confirmed a date and time for the appointment.
     */
    case SCHEDULED = 'scheduled';

    /**
     * Client attended and the e.firma was successfully issued.
     * Admin must upload .key, .cer, and password to complete this stage.
     */
    case ATTENDED_APPROVED = 'attended_approved';

    /**
     * Client attended but the appointment was rejected by SAT.
     * A new appointment must be requested.
     */
    case ATTENDED_REJECTED = 'attended_rejected';

    /**
     * Client did not attend the scheduled appointment.
     * A new appointment must be requested.
     */
    case NO_SHOW = 'no_show';

    /**
     * Return a human-readable Spanish label for display in the dashboard.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING_SCHEDULING => 'Cita solicitada',
            self::SCHEDULED => 'Cita agendada',
            self::ATTENDED_APPROVED => 'Asistió — Aprobado',
            self::ATTENDED_REJECTED => 'Asistió — Rechazado',
            self::NO_SHOW => 'No asistió',
        };
    }

    /**
     * Return the badge color for this status (Filament palette).
     */
    public function color(): string
    {
        return match ($this) {
            self::PENDING_SCHEDULING => 'warning',
            self::SCHEDULED => 'info',
            self::ATTENDED_APPROVED => 'success',
            self::ATTENDED_REJECTED => 'danger',
            self::NO_SHOW => 'danger',
        };
    }

    /**
     * Return true when this status requires scheduling a new appointment.
     *
     * Used by the dashboard to determine if the "Solicitar nueva cita" action
     * should be available.
     */
    public function requiresRescheduling(): bool
    {
        return in_array($this, [self::ATTENDED_REJECTED, self::NO_SHOW], true);
    }
}
