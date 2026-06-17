<?php

namespace App\Enums;

/**
 * Distinguishes whether a task was created manually by a team member or by an automated process.
 *
 * When a previously manual task gets automated, its type changes to AUTOMATED
 * and the automated_by field records which service completed it.
 * The historical record remains intact in the tasks table.
 */
enum TaskTypeEnum: string
{
    case MANUAL    = 'manual';
    case AUTOMATED = 'automated';

    /**
     * Return a human-readable Spanish label for display in the dashboard.
     *
     * @return string
     */
    public function label(): string
    {
        return match($this) {
            self::MANUAL    => 'Manual',
            self::AUTOMATED => 'Automatizada',
        };
    }
}
