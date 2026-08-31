<?php

namespace App\Models;

use App\Enums\AppointmentEventTypeEnum;
use App\Enums\AppointmentStatusEnum;
use App\Enums\AppointmentTypeEnum;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

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
        'preferred_module',
        'email_alias',
        'acknowledgment_path',
        'notes',
        'rejection_reason',
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

    /**
     * Get this appointment's timeline, newest first.
     *
     * @return HasMany<AppointmentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AppointmentEvent::class)->latest('created_at');
    }

    // -------------------------------------------------------------------------
    // Timeline
    // -------------------------------------------------------------------------

    /**
     * Append an immutable event to this appointment's timeline.
     *
     * When a signed-in user triggers the action the actor is that user; otherwise it is
     * the bot or the system. Mirrors LegalName::recordEvent().
     *
     * @param  AppointmentEventTypeEnum  $type  What happened.
     * @param  string|null  $description  Human-readable summary shown in the timeline.
     * @param  array<string, mixed>  $metadata  Event-specific payload (office, reason…).
     * @param  string|null  $actorType  Fallback actor when nobody is signed in.
     */
    public function recordEvent(
        AppointmentEventTypeEnum $type,
        ?string $description = null,
        array $metadata = [],
        ?string $actorType = 'bot',
    ): AppointmentEvent {
        $user = Auth::user();

        return $this->events()->create([
            'type' => $type,
            'actor_type' => $user ? 'user' : $actorType,
            'actor_id' => $user?->getKey(),
            'description' => $description,
            'metadata' => $metadata ?: null,
        ]);
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
