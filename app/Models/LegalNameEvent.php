<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LegalNameEventTypeEnum;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable timeline event for a denomination (LegalName).
 *
 * Append-only audit record: once written it is never updated or deleted.
 * Only created_at is tracked; updated_at is intentionally disabled.
 */
class LegalNameEvent extends Model
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
        'legal_name_id',
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
            'type' => LegalNameEventTypeEnum::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * Get the denomination this event belongs to.
     *
     * @return BelongsTo<LegalName, $this>
     */
    public function legalName(): BelongsTo
    {
        return $this->belongsTo(LegalName::class);
    }

    /**
     * Get the user who triggered the event, when actor_type = user.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
