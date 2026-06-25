<?php

namespace App\Models;

use App\Enums\LegalNameEventTypeEnum;
use App\Enums\LegalNameStatusEnum;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

/**
 * Represents a proposed company denomination submitted to the SE for approval.
 *
 * A registration may have up to 4 proposals ordered by priority.
 * Validation rules mirror the Tally system: minimum 3 to allow deletion,
 * maximum 4 total, and names in PROCESS or APPROVED status cannot be modified.
 * Each denomination is linked to a MuaAccount (soldado) whose FIEL is used
 * to submit the reservation to the MUA portal.
 */
class LegalName extends Model
{
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'registration_id',
        'name',
        'company_type',
        'priority',
        'status',
        'clave_unica_denominacion',
        'authorization_timestamp',
        'submitted_at',
        'mua_account_id',
        'rejection_reason',
        'mua_available',
        'portal_status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LegalNameStatusEnum::class,
            'authorization_timestamp' => 'datetime',
            'submitted_at' => 'datetime',
            'mua_available' => 'boolean',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * Get the registration this denomination belongs to.
     *
     * @return BelongsTo<Registration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    /**
     * Get the MUA account (soldado) assigned to process this denomination.
     *
     * @return BelongsTo<MuaAccount, $this>
     */
    public function muaAccount(): BelongsTo
    {
        return $this->belongsTo(MuaAccount::class);
    }

    /**
     * Get the lifecycle timeline events for this denomination, oldest first.
     *
     * Ordered by created_at and then id (ULID) so events written within the same
     * second — created_at only has second precision — keep a stable chronological
     * order, since ULIDs are time-sortable.
     *
     * @return HasMany<LegalNameEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(LegalNameEvent::class)
            ->orderBy('created_at')
            ->orderBy('id');
    }

    // -------------------------------------------------------------------------
    // Business logic helpers
    // -------------------------------------------------------------------------

    /**
     * Append an immutable event to this denomination's lifecycle timeline.
     *
     * Resolves the actor automatically: an authenticated dashboard user is
     * recorded as `user`, otherwise the event is attributed to the given actor
     * type (defaults to `system` for cron/jobs, pass `bot` for bot callbacks).
     *
     * @param  LegalNameEventTypeEnum  $type  The event type.
     * @param  string|null  $description  Optional human-readable summary.
     * @param  array<string, mixed>  $metadata  Event-specific payload (FIEL, folio, error, etc.).
     * @param  string|null  $actorType  Override actor type when no user is authenticated.
     * @return LegalNameEvent The persisted event.
     */
    public function recordEvent(
        LegalNameEventTypeEnum $type,
        ?string $description = null,
        array $metadata = [],
        ?string $actorType = 'system',
    ): LegalNameEvent {
        $user = Auth::user();

        return $this->events()->create([
            'type' => $type,
            'actor_type' => $user ? 'user' : $actorType,
            'actor_id' => $user?->getKey(),
            'description' => $description,
            'metadata' => $metadata ?: null,
        ]);
    }

    /**
     * Determine whether this denomination can still be edited or deleted.
     *
     * Delegates to the enum to keep business rules in one place.
     */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Determine whether this denomination is waiting to be submitted to MUA.
     */
    public function isWaitingForSubmission(): bool
    {
        return $this->status === LegalNameStatusEnum::WAIT;
    }

    /**
     * Determine whether this denomination has been submitted and is awaiting SE response.
     */
    public function isInProcess(): bool
    {
        return in_array($this->status, [
            LegalNameStatusEnum::PENDING,
            LegalNameStatusEnum::PROCESS,
        ], true);
    }
}
