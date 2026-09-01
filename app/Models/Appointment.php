<?php

namespace App\Models;

use App\Enums\AppointmentEventTypeEnum;
use App\Enums\AppointmentStatusEnum;
use App\Enums\AppointmentTypeEnum;
use App\Observers\AppointmentObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
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
#[ObservedBy(AppointmentObserver::class)]
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
        'payment_amount',
        'paid_at',
        'paid_by',
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
            'payment_amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    /** IVA rate applied on top of the payment subtotal. */
    public const IVA_RATE = 0.16;

    // -------------------------------------------------------------------------
    // Payment
    // -------------------------------------------------------------------------

    /** IVA (16%) computed from the payment subtotal. */
    public function paymentIva(): float
    {
        return round((float) $this->payment_amount * self::IVA_RATE, 2);
    }

    /** Total = subtotal + IVA. */
    public function paymentTotal(): float
    {
        return round((float) $this->payment_amount * (1 + self::IVA_RATE), 2);
    }

    /**
     * Payment state used across the payments board:
     *   'pagada'    — already paid (paid_at set).
     *   'pendiente' — payable and not yet paid.
     *   'aun_no'    — not payable yet (date not passed, or result not captured).
     */
    public function paymentState(): string
    {
        if ($this->paid_at !== null) {
            return 'pagada';
        }

        return $this->isPayable() ? 'pendiente' : 'aun_no';
    }

    /**
     * A cita is payable only once its date has passed AND we already hold its result — the RFC for
     * an RFC cita, or the company e.firma for a FIEL cita. Rejected / cancelled / no-show are never
     * paid. This is why "if the soldado uploads nothing, it stays out of pending payment".
     */
    public function isPayable(): bool
    {
        if (in_array($this->status, [
            AppointmentStatusEnum::REJECTED,
            AppointmentStatusEnum::CANCELLED,
            AppointmentStatusEnum::NO_SHOW,
        ], true)) {
            return false;
        }

        if ($this->scheduled_at === null || $this->scheduled_at->isFuture()) {
            return false;
        }

        $registration = $this->registration;
        if ($registration === null) {
            return false;
        }

        return $this->type === AppointmentTypeEnum::RFC
            ? filled($registration->rfc)
            : filled($registration->company_fiel_cer_path);
    }

    /**
     * Scope to citas that are payable (payment pending or already paid) — the universe of the
     * payments board. Mirrors isPayable() at the query level.
     *
     * @param  Builder<Appointment>  $query
     */
    public function scopePayable($query): void
    {
        $query->whereNotIn('status', [
            AppointmentStatusEnum::REJECTED->value,
            AppointmentStatusEnum::CANCELLED->value,
            AppointmentStatusEnum::NO_SHOW->value,
        ])
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<', now())
            ->where(function ($q): void {
                $q->where(function ($rfc): void {
                    $rfc->where('type', AppointmentTypeEnum::RFC->value)
                        ->whereHas('registration', fn ($r) => $r->whereNotNull('rfc')->where('rfc', '!=', ''));
                })->orWhere(function ($fiel): void {
                    $fiel->where('type', AppointmentTypeEnum::FIEL->value)
                        ->whereHas('registration', fn ($r) => $r->whereNotNull('company_fiel_cer_path'));
                });
            });
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
