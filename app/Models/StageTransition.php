<?php

namespace App\Models;

use App\Enums\RegistrationStageEnum;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable record of every stage change in a registration expedient.
 *
 * Once created, a transition is never updated or deleted — it is an append-only audit log.
 * Only created_at is tracked; updated_at is intentionally omitted.
 */
class StageTransition extends Model
{
    use HasFactory, HasUlids;

    /**
     * Disable updated_at since transitions are immutable records.
     */
    const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'registration_id',
        'from_stage',
        'to_stage',
        'performed_by',
        'reason',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // from_stage is nullable — the initial webhook arrival has no preceding stage.
            // Laravel's enum cast handles null values transparently when the column is nullable.
            'from_stage' => RegistrationStageEnum::class,
            'to_stage'   => RegistrationStageEnum::class,
            'created_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * Get the registration this transition belongs to.
     *
     * @return BelongsTo<Registration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    /**
     * Get the user who performed this stage transition.
     *
     * @return BelongsTo<User, $this>
     */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
