<?php

namespace App\Models;

use App\Enums\AppointmentEventTypeEnum;
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
        'last_used_at',
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
            'last_used_at' => 'datetime',
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
            // The SAT keeps an address "in use" for about a day AFTER the cita — reusing it
            // sooner is rejected with error_on_correo_repetido. So an address only frees 24h
            // past its cita date, regardless of whether the cita succeeded or failed.
            $cutoff = now()->subHours(24);

            // Addresses sitting on ANOTHER appointment that is still active OR whose cita was
            // less than 24h ago. Pending/formed have no date yet (actively forming); scheduled
            // is blocked until 24h after its date.
            $blocked = Appointment::query()
                ->whereKeyNot($appointment->getKey())
                ->whereNotNull('email_alias')
                ->where(function ($q): void {
                    $q->whereIn('status', [
                        AppointmentStatusEnum::PENDING_FORMING->value,
                        AppointmentStatusEnum::FORMED->value,
                    ])->orWhere(function ($q2) use ($cutoff): void {
                        $q2->whereNotNull('scheduled_at')
                            ->where('scheduled_at', '>=', $cutoff);
                    });
                })
                ->pluck('email_alias')
                ->all();

            // Addresses used to form in the last 24h — burned at the SAT even if the forming
            // failed and the appointment no longer carries the alias (see the failure path,
            // which clears email_alias so the retry claims a fresh address instead of the
            // burned one). last_used_at is the cooldown clock.
            $coolingDown = self::query()
                ->whereNotNull('last_used_at')
                ->where('last_used_at', '>=', $cutoff)
                ->pluck('address')
                ->all();

            // A cita must not reuse the mailbox of another LIVE cita of the SAME expediente:
            // the e.firma cita has to go on a different address than the RFC cita used, even
            // once that address is out of cooldown. (Cancelled/rejected/no-show siblings do
            // not reserve their address.)
            $siblingAliases = Appointment::query()
                ->where('registration_id', $appointment->registration_id)
                ->whereKeyNot($appointment->getKey())
                ->whereNotNull('email_alias')
                ->whereIn('status', [
                    AppointmentStatusEnum::PENDING_FORMING->value,
                    AppointmentStatusEnum::FORMED->value,
                    AppointmentStatusEnum::SCHEDULED->value,
                    AppointmentStatusEnum::ATTENDED->value,
                ])
                ->pluck('email_alias')
                ->all();

            $offLimits = array_values(array_unique([...$blocked, ...$coolingDown, ...$siblingAliases]));

            // Self-heal: if this cita is still pending to form and its LAST forming attempt
            // failed, the address it used is burned at the SAT (reusing it repeats the
            // error_on_correo_repetido). Retire it — cooldown + off-limits — so this claim
            // hands out a fresh address in a single re-form, without waiting for the failure
            // callback to detach it.
            $current = $appointment->email_alias;
            if (filled($current) && self::currentAliasIsBurned($appointment)) {
                self::query()->where('address', $current)->update(['last_used_at' => now()]);
                $offLimits[] = $current;
                $appointment->update(['email_alias' => null]);
                $current = null;
            }

            // Keep the address already on this appointment, but only if nothing else is
            // holding or cooling it down (that is the DONGHAI/LI BAO collision).
            if (filled($current) && ! in_array($current, $offLimits, true)) {
                return $current;
            }

            $email = self::query()
                ->whereNotIn('address', $offLimits)
                ->orderBy('address')
                ->lockForUpdate()
                ->first();

            if ($email === null) {
                return null;
            }

            $email->update(['is_free' => false, 'last_used_at' => now()]);
            $appointment->update(['email_alias' => $email->address]);

            return $email->address;
        });
    }

    /**
     * True when the appointment's current alias was burned by a failed forming.
     *
     * The cita must still be pending to form, and its newest FAILED event must be at or
     * after its newest FORM_DISPATCHED event — i.e., the last thing that happened to the
     * forming was a failure (e.g. error_on_correo_repetido), so the SAT still holds that
     * address and reusing it would fail again.
     */
    protected static function currentAliasIsBurned(Appointment $appointment): bool
    {
        if ($appointment->status !== AppointmentStatusEnum::PENDING_FORMING) {
            return false;
        }

        $lastFailure = $appointment->events()
            ->where('type', AppointmentEventTypeEnum::FAILED->value)
            ->max('created_at');

        if ($lastFailure === null) {
            return false;
        }

        $lastDispatch = $appointment->events()
            ->where('type', AppointmentEventTypeEnum::FORM_DISPATCHED->value)
            ->max('created_at');

        return $lastDispatch === null || $lastFailure >= $lastDispatch;
    }

    /**
     * Free the alias a failed forming was using so the retry claims a FRESH address.
     *
     * The address it was on is burned at the SAT (error_on_correo_repetido on reuse), so we
     * do NOT hand it back — last_used_at keeps it on cooldown for 24h — we only detach it
     * from the appointment. Call this from the /callback failure path.
     */
    public static function releaseBurnedAlias(Appointment $appointment): void
    {
        $alias = $appointment->email_alias;

        if (blank($alias)) {
            return;
        }

        // Stamp the cooldown clock (in case it was formed out-of-band) and detach.
        self::query()->where('address', $alias)->update(['last_used_at' => now()]);
        $appointment->update(['email_alias' => null]);
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
