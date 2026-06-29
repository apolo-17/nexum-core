<?php

namespace App\Models;

use App\Enums\AppointmentTypeEnum;
use App\Enums\EfirmaAppointmentStatusEnum;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single SAT appointment for a company's incorporation (RFC or FIEL).
 *
 * Each registration needs one RFC appointment and one FIEL appointment. The soldado
 * who attends is linked when known. Captured manually today; the SAT bot will later
 * fill scheduled_at / office / status via a callback.
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
            'status' => EfirmaAppointmentStatusEnum::class,
            'scheduled_at' => 'datetime',
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
     * Determine whether this appointment has been completed successfully.
     */
    public function isCompleted(): bool
    {
        return $this->status === EfirmaAppointmentStatusEnum::ATTENDED_APPROVED;
    }
}
