<?php

namespace App\Models;

use App\Enums\WebhookEventStatusEnum;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Records every incoming event from the Singapur relay for idempotent processing.
 *
 * Before processing any incoming payload, the system checks whether event_id
 * already exists here. If it does, the event is skipped to avoid duplicate data.
 * Failed events can be retried by resetting status back to PENDING.
 */
class WebhookEvent extends Model
{
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'source',
        'payload',
        'status',
        'processed_at',
        'error_message',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status'       => WebhookEventStatusEnum::class,
            'payload'      => 'array',
            'processed_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Business logic helpers
    // -------------------------------------------------------------------------

    /**
     * Determine whether this event has already been successfully processed.
     *
     * @return bool
     */
    public function isProcessed(): bool
    {
        return $this->status === WebhookEventStatusEnum::PROCESSED;
    }
}
