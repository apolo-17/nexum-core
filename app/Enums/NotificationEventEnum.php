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
     * A denomination was successfully registered at the SE portal by the MUA bot
     * (the bot confirmed the submission — `submitted` callback).
     */
    case DENOMINATION_SUBMITTED = 'denomination_submitted';

    /**
     * The SE approved a denomination (`approved` callback). The constancia was
     * received and the name is authorized.
     */
    case DENOMINATION_APPROVED = 'denomination_approved';

    /**
     * The MUA bot could not register a denomination at the SE (technical failure —
     * `failed` callback). The name returns to the queue for a manual resend.
     */
    case DENOMINATION_FAILED = 'denomination_failed';

    /**
     * Return a human-readable Spanish label for display in the settings UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::EXPEDIENTE_RECEIVED => 'Recepción de expedientes',
            self::DENOMINATION_SUBMITTED => 'Denominación registrada en la SE',
            self::DENOMINATION_APPROVED => 'Denominación aprobada',
            self::DENOMINATION_FAILED => 'Error al enviar denominación',
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
            self::DENOMINATION_SUBMITTED => 'Avisa cuando el bot registra correctamente una '
                .'denominación en el portal de la SE.',
            self::DENOMINATION_APPROVED => 'Avisa cuando la SE autoriza una denominación '
                .'(constancia recibida).',
            self::DENOMINATION_FAILED => 'Avisa cuando el bot no pudo registrar una '
                .'denominación en la SE por un fallo técnico (regresa a la cola para reenviar).',
        };
    }
}
