<?php

namespace App\Models;

use App\Enums\ShareholderRoleEnum;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a shareholder or legal representative of the company being incorporated.
 *
 * Each shareholder belongs to a registration and carries identity data
 * received from the Singapur relay (passport, nationality, participation percentage).
 */
class Shareholder extends Model
{
    use HasFactory, HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'registration_id',
        'name',
        'nationality',
        'passport_number',
        'participation_percentage',
        'role',
        'email',
        'phone',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role'                     => ShareholderRoleEnum::class,
            'participation_percentage' => 'decimal:2',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * Get the registration this shareholder belongs to.
     *
     * @return BelongsTo<Registration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }
}
