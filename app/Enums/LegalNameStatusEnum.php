<?php

namespace App\Enums;

/**
 * Represents the approval lifecycle of a proposed company denomination before the SE.
 *
 * Mirrors the Tally validation flow: WAIT → PENDING → PROCESS → APPROVED | REJECTED.
 */
enum LegalNameStatusEnum: string
{
    /** Pool name generated (e.g. by AI) but not yet sent to the SE — awaits review. */
    case DRAFT = 'draft';

    case WAIT = 'wait';
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
            self::WAIT => 'En espera',
            self::PENDING => 'Pendiente de envío',
            self::PROCESS => 'En dictamen',
            self::APPROVED => 'Aprobado',
            self::REJECTED => 'Rechazado',
        };
    }

    /**
     * Determine whether this status allows the denomination to be modified.
     *
     * Denominations in PROCESS or APPROVED state cannot be edited.
     */
    public function isEditable(): bool
    {
        return match ($this) {
            self::PROCESS, self::APPROVED => false,
            default => true,
        };
    }
}
