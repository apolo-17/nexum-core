<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AppointmentEventTypeEnum;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable timeline event for a SAT appointment.
 *
 * Append-only audit record: once written it is never updated or deleted.
 * Only created_at is tracked; updated_at is intentionally disabled.
 */
class AppointmentEvent extends Model
{
    use HasFactory, HasUlids;

    /**
     * Disable updated_at — events are immutable.
     */
    const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'appointment_id',
        'type',
        'actor_type',
        'actor_id',
        'description',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AppointmentEventTypeEnum::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the appointment this event belongs to.
     *
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * Get the user who caused this event, when it was not the bot.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
