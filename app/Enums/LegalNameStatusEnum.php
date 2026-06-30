<?php

namespace App\Enums;

/**
 * Represents the approval lifecycle of a proposed company denomination before the SE.
 *
 * Flow: WAIT → SUBMITTING → PENDING → PROCESS → APPROVED | REJECTED.
 *
 * SUBMITTING is an honest in-flight state: the request was dispatched to the bot
 * but the SE has NOT confirmed registration yet. Only the bot's signed `submitted`
 * callback advances it to PENDING ("Enviada a la SE"), so that label always means
 * a confirmed fact, never an optimistic guess.
 */
enum LegalNameStatusEnum: string
{
    /** Pool name generated (e.g. by AI) but not yet sent to the SE — awaits review. */
    case DRAFT = 'draft';

    case WAIT = 'wait';
    case SUBMITTING = 'submitting';
    case PENDING = 'pending';
    case PROCESS = 'process';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    /**
     * Return a human-readable Spanish label for display in the dashboard.
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Borrador (sin enviar)',
            self::WAIT => 'En cola de envío',
            self::SUBMITTING => 'Enviando a la SE…',
            self::PENDING => 'Enviada a la SE',
            self::PROCESS => 'En dictamen',
            self::APPROVED => 'Aprobada',
            self::REJECTED => 'Rechazada',
        };
    }

    /**
     * Return the Filament color token for the status badge.
     *
     * Groups the lifecycle visually: pre-send (gray), enviando sin confirmar
     * (warning/amber), enviada-confirmada a la SE (info/blue), en dictamen
     * (warning/amber) and the terminal outcomes (green/red).
     */
    public function color(): string
    {
        return match ($this) {
            self::DRAFT, self::WAIT => 'gray',
            self::SUBMITTING, self::PROCESS => 'warning',
            self::PENDING => 'info',
            self::APPROVED => 'success',
            self::REJECTED => 'danger',
        };
    }

    /**
     * Determine whether this status allows the denomination to be modified.
     *
     * In-flight (SUBMITTING) and post-dictamen states cannot be edited.
     */
    public function isEditable(): bool
    {
        return match ($this) {
            self::SUBMITTING, self::PROCESS, self::APPROVED => false,
            default => true,
        };
    }
}
