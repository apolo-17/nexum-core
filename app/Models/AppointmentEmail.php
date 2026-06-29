<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A mailbox address in the pool used by the SAT bot to receive appointment tokens.
 *
 * All pool addresses deliver to a single shared mailbox (catch-all or aliases); the
 * bot distinguishes each token by the message's To: header. Nexum only tracks which
 * address is free vs. assigned.
 */
class AppointmentEmail extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'address',
        'is_free',
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
            'is_free' => 'boolean',
        ];
    }
}
