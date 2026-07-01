<?php

namespace App\Models;

use App\Enums\AppointmentStatusEnum;
use App\Enums\AppointmentTypeEnum;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single SAT appointment for a company's incorporation (RFC or FIEL).
 *
 * Lifecycle: the team forms the appointment MANUALLY at the SAT portal (pending_forming
 * → formed), then the nexum-citas-sat bot reviews the formed ones and, when the SAT
 * assigns a slot, fills scheduled_at / office / acuse via the callback (→ scheduled).
 */
class Appointment extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'registration_id',
        'soldado_id',
        'type',
        'status',
        'scheduled_at',
        'formed_at',
        'last_review_at',
        'office',
        'email_alias',
        'acknowledgment_path',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AppointmentTypeEnum::class,
            'status' => AppointmentStatusEnum::class,
            'scheduled_at' => 'datetime',
            'formed_at' => 'datetime',
            'last_review_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * Get the registration this appointment belongs to.
     *
     * @return BelongsTo<Registration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    /**
     * Get the soldado who attends this appointment, if assigned.
     *
     * @return BelongsTo<Soldado, $this>
     */
    public function soldado(): BelongsTo
    {
        return $this->belongsTo(Soldado::class);
    }

    // -------------------------------------------------------------------------
    // Business logic helpers
    // -------------------------------------------------------------------------

    /**
     * Determine whether this appointment has been scheduled (slot assigned by the SAT).
     */
    public function isScheduled(): bool
    {
        return $this->status === AppointmentStatusEnum::SCHEDULED;
    }

    /**
     * Determine whether this appointment is formed and awaiting the bot's review.
     */
    public function isAwaitingReview(): bool
    {
        return $this->status === AppointmentStatusEnum::FORMED;
    }
}
