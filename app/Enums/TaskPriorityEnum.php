<?php

namespace App\Enums;

/**
 * Defines the urgency levels for tasks within a registration expedient.
 */
enum TaskPriorityEnum: string
{
    case LOW    = 'low';
    case MEDIUM = 'medium';
    case HIGH   = 'high';

    /**
     * Return a human-readable Spanish label for display in the dashboard.
     *
     * @return string
     */
    public function label(): string
    {
        return match($this) {
            self::LOW    => 'Baja',
            self::MEDIUM => 'Media',
            self::HIGH   => 'Alta',
        };
    }
}
