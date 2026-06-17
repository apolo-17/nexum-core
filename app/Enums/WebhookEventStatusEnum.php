<?php

namespace App\Enums;

/**
 * Tracks the processing lifecycle of an incoming webhook event from the Singapur relay.
 */
enum WebhookEventStatusEnum: string
{
    case PENDING   = 'pending';
    case PROCESSED = 'processed';
    case FAILED    = 'failed';

    /**
     * Return a human-readable Spanish label for display in the dashboard.
     *
     * @return string
     */
    public function label(): string
    {
        return match($this) {
            self::PENDING   => 'Pendiente',
            self::PROCESSED => 'Procesado',
            self::FAILED    => 'Fallido',
        };
    }
}
