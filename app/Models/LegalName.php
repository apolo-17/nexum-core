<?php

namespace App\Models;

use App\Enums\LegalNameStatusEnum;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'priority',
        'status',
        'clave_unica_denominacion',
        'authorization_timestamp',
        'submitted_at',
        'mua_account_id',
        'rejection_reason',
        'mua_available',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status'                  => LegalNameStatusEnum::class,
            'authorization_timestamp' => 'datetime',
            'submitted_at'            => 'datetime',
            'mua_available'           => 'boolean',
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

    // -------------------------------------------------------------------------
    // Business logic helpers
    // -------------------------------------------------------------------------

    /**
     * Determine whether this denomination can still be edited or deleted.
     *
     * Delegates to the enum to keep business rules in one place.
     *
     * @return bool
     */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Determine whether this denomination is waiting to be submitted to MUA.
     *
     * @return bool
     */
    public function isWaitingForSubmission(): bool
    {
        return $this->status === LegalNameStatusEnum::WAIT;
    }

    /**
     * Determine whether this denomination has been submitted and is awaiting SE response.
     *
     * @return bool
     */
    public function isInProcess(): bool
    {
        return in_array($this->status, [
            LegalNameStatusEnum::PENDING,
            LegalNameStatusEnum::PROCESS,
        ], true);
    }
}
