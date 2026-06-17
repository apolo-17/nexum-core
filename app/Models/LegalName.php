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
}
