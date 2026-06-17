<?php

namespace App\Enums;

/**
 * Represents the overall operational status of a registration expedient.
 *
 * Differs from stage: status reflects whether the case is active,
 * paused, cancelled, or fully done regardless of which stage it is in.
 */
enum RegistrationStatusEnum: string
{
    case ACTIVE    = 'active';
    case ON_HOLD   = 'on_hold';
    case CANCELLED = 'cancelled';
    case COMPLETED = 'completed';

    /**
     * Return a human-readable Spanish label for display in the dashboard.
     *
     * @return string
     */
    public function label(): string
    {
        return match($this) {
            self::ACTIVE    => 'Activo',
            self::ON_HOLD   => 'En pausa',
            self::CANCELLED => 'Cancelado',
            self::COMPLETED => 'Completado',
        };
    }
}
