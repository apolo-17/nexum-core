<?php

namespace App\Models;

use App\Enums\AppointmentStatusEnum;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

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

    /**
     * Claim a pool address for an appointment, honoring the "no reuse until the cita
     * passes" rule.
     *
     * An address is OFF-LIMITS while it sits on ANY other appointment that is still
     * active — pending to form, formed, or scheduled with a date that has NOT yet
     * passed. It becomes free again on its own once that cita's date passes (or the cita
     * is rejected / no_show). Availability is computed live from the appointments, so a
     * stale is_free flag can never hand out an address that is still in use.
     *
     * Returns the address (existing one kept if still valid, otherwise a fresh free one),
     * or null when the pool is exhausted. Runs in a locked transaction so two concurrent
     * claims cannot grab the same address.
     *
     * @param  Appointment  $appointment  The appointment to give a mailbox to.
     */
    public static function claimFor(Appointment $appointment): ?string
    {
        return DB::transaction(function () use ($appointment): ?string {
            $blocked = Appointment::query()
                ->whereKeyNot($appointment->getKey())
                ->whereNotNull('email_alias')
                ->where(function ($q): void {
                    $q->whereIn('status', [
                        AppointmentStatusEnum::PENDING_FORMING->value,
                        AppointmentStatusEnum::FORMED->value,
                    ])->orWhere(function ($q2): void {
                        $q2->where('status', AppointmentStatusEnum::SCHEDULED->value)
                            ->where('scheduled_at', '>=', now());
                    });
                })
                ->pluck('email_alias')
                ->all();

            // Keep the address already on this appointment, but only if no other active
            // cita is holding it (that is exactly the DONGHAI/LI BAO collision).
            $current = $appointment->email_alias;
            if (filled($current) && ! in_array($current, $blocked, true)) {
                return $current;
            }

            $email = self::query()
                ->whereNotIn('address', $blocked)
                ->orderBy('address')
                ->lockForUpdate()
                ->first();

            if ($email === null) {
                return null;
            }

            $email->update(['is_free' => false]);
            $appointment->update(['email_alias' => $email->address]);

            return $email->address;
        });
    }

    /**
     * Get the appointments that have used this pool address (matched by email_alias).
     *
     * Linked by the address string rather than a FK, since the alias is copied onto the
     * appointment when assigned. Includes current and historical (scheduled) uses.
     *
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'email_alias', 'address')->latest();
    }
}
