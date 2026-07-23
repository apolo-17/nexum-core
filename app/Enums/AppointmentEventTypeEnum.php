<?php

namespace App\Enums;

/**
 * Types of events recorded on a SAT appointment's timeline.
 *
 * Mirrors LegalNameEventTypeEnum (the MUA bot's history). The point is to see whether
 * the bot has actually been working on an appointment and what the SAT answered each
 * time, not just the latest status.
 */
enum AppointmentEventTypeEnum: string
{
    /**
     * The appointment was created in Nexum, awaiting forming.
     */
    case CREATED = 'created';

    /**
     * Nexum pushed the appointment to the bot to be formed (POST /form).
     */
    case FORM_DISPATCHED = 'form_dispatched';

    /**
     * The bot queued it in the SAT virtual queue (`formed` callback).
     */
    case FORMED = 'formed';

    /**
     * The bot checked the SAT and there is still no slot (`in_review` callback).
     */
    case REVIEWED = 'reviewed';

    /**
     * The SAT assigned a date and time (`scheduled` callback).
     */
    case SCHEDULED = 'scheduled';

    /**
     * The bot could not form or review it (`failed` callback).
     */
    case FAILED = 'failed';

    /**
     * The SAT rejected the appointment.
     */
    case REJECTED = 'rejected';

    /**
     * The soldado did not show up.
     */
    case NO_SHOW = 'no_show';

    /**
     * Someone on the team marked it formed by hand, without the bot.
     */
    case MARKED_MANUALLY = 'marked_manually';

    /**
     * Return a human-readable Spanish label for the timeline.
     */
    public function label(): string
    {
        return match ($this) {
            self::CREATED => 'Cita creada',
            self::FORM_DISPATCHED => 'Enviada al bot para formar',
            self::FORMED => 'Formada en la fila virtual',
            self::REVIEWED => 'Revisada, aún sin lugar',
            self::SCHEDULED => 'El SAT asignó fecha y hora',
            self::FAILED => 'Error del bot',
            self::REJECTED => 'Rechazada por el SAT',
            self::NO_SHOW => 'No se presentó',
            self::MARKED_MANUALLY => 'Marcada formada a mano',
        };
    }

    /**
     * Heroicon name for the timeline marker.
     */
    public function icon(): string
    {
        return match ($this) {
            self::CREATED => 'heroicon-o-sparkles',
            self::FORM_DISPATCHED => 'heroicon-o-paper-airplane',
            self::FORMED => 'heroicon-o-queue-list',
            self::REVIEWED => 'heroicon-o-arrow-path',
            self::SCHEDULED => 'heroicon-o-calendar-days',
            self::FAILED => 'heroicon-o-exclamation-triangle',
            self::REJECTED => 'heroicon-o-x-circle',
            self::NO_SHOW => 'heroicon-o-user-minus',
            self::MARKED_MANUALLY => 'heroicon-o-hand-raised',
        };
    }

    /**
     * Filament color token for the timeline marker.
     */
    public function color(): string
    {
        return match ($this) {
            self::SCHEDULED, self::FORMED => 'success',
            self::FAILED, self::REJECTED, self::NO_SHOW => 'danger',
            self::FORM_DISPATCHED, self::MARKED_MANUALLY => 'warning',
            self::CREATED, self::REVIEWED => 'gray',
        };
    }
}
