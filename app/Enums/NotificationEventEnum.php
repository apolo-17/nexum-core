<?php

namespace App\Enums;

/**
 * Catalog of configurable notification events surfaced in the "Notificaciones"
 * settings module.
 *
 * Each case represents a distinct business event a super_admin can enable or
 * disable and route to a chosen set of recipients. New events are added here and
 * become available in the settings UI automatically (rows are self-healing via
 * NotificationSetting::ensureEventsExist()). For now the only event is the
 * reception of a new expedient from the Singapur relay, which fires both when the
 * registration is created successfully and when processing fails.
 */
enum NotificationEventEnum: string
{
    /**
     * A new expedient arrived from the Singapur relay (covers both the successful
     * upsert and a processing failure — the notification body distinguishes them).
     */
    case EXPEDIENTE_RECEIVED = 'expediente_received';

    /**
     * Return a human-readable Spanish label for display in the settings UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::EXPEDIENTE_RECEIVED => 'Recepción de expedientes',
        };
    }

    /**
     * Return a longer Spanish description shown next to the event in the form.
     */
    public function description(): string
    {
        return match ($this) {
            self::EXPEDIENTE_RECEIVED => 'Avisa cuando China envía un nuevo expediente, '
                .'tanto si se dio de alta correctamente como si falló su procesamiento.',
        };
    }
}
