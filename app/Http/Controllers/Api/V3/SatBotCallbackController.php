<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V3;

use App\Enums\AppointmentEventTypeEnum;
use App\Enums\AppointmentStatusEnum;
use App\Enums\NotificationEventEnum;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentEmail;
use App\Notifications\SatAppointmentScheduledNotification;
use App\Notifications\SatAppointmentStatusNotification;
use App\Services\Notifications\EventNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives callbacks from the nexum-citas-sat bot working a SAT appointment.
 *
 * The bot runs two phases: it FORMS the appointment in the SAT virtual queue (phase 1,
 * fed by SatBotFormingController) and then REVIEWS it until the SAT assigns a slot
 * (phase 2, fed by SatBotReviewController). Auth: HMAC-SHA256 over
 * {appointment_id, status, timestamp} in the X-Signature header (identical scheme to
 * MuaBotCallbackController). Statuses the bot reports:
 *
 *  - formed    : queued in the SAT virtual queue → mark formed + formed_at, keep the alias.
 *  - scheduled : SAT assigned a slot → fill date/office/acuse → R2, free the alias, notify.
 *  - in_review : checked, still no slot → bump last_review_at, stay formed.
 *  - rejected  : SAT rejected the appointment → mark rejected.
 *  - no_show   : the soldado did not show up → mark no_show.
 *  - failed    : the bot could not form/review (transient error) → note it and retry later.
 *
 * See docs/CONTRACT.md in the nexum-citas-sat repo.
 */
class SatBotCallbackController extends Controller
{
    /**
     * Maximum allowed age of a request timestamp in seconds (anti-replay).
     */
    private const MAX_TIMESTAMP_DIFF_SECONDS = 300;

    /**
     * Known statuses the bot may report (not all map 1:1 to AppointmentStatusEnum).
     *
     * @var list<string>
     */
    private const KNOWN_STATUSES = [
        'formed',
        'scheduled',
        'in_review',
        'rejected',
        'no_show',
        'failed',
    ];

    /**
     * Handle a SAT appointment review callback from the bot.
     *
     * @param  Request  $request  Signed callback request.
     */
    public function handle(Request $request): JsonResponse
    {
        $signature = $request->header('X-Signature');

        if (! $signature || ! is_string($signature)) {
            return response()->json(['error' => 'Missing signature'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = [
            'appointment_id' => (string) $request->input('appointment_id'),
            'status' => (string) $request->input('status'),
            'timestamp' => (int) $request->input('timestamp'),
        ];

        if (! $this->isValidSignature($payload, $signature)) {
            Log::warning('SAT bot callback: invalid HMAC signature.', ['ip' => $request->ip()]);

            return response()->json(['error' => 'Invalid signature'], Response::HTTP_UNAUTHORIZED);
        }

        if (abs(time() - $payload['timestamp']) > self::MAX_TIMESTAMP_DIFF_SECONDS) {
            return response()->json(['error' => 'Request expired'], Response::HTTP_UNAUTHORIZED);
        }

        if (! in_array($payload['status'], self::KNOWN_STATUSES, true)) {
            return response()->json(['error' => 'Invalid status value'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $appointment = Appointment::with(['registration', 'soldado.user'])->find($payload['appointment_id']);

        if (! $appointment) {
            return response()->json(['error' => 'Appointment not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            match ($payload['status']) {
                'formed' => $this->processFormed($request, $appointment),
                'scheduled' => $this->processScheduled($request, $appointment),
                'in_review' => $this->processInReview($appointment),
                'failed' => $this->processFailure($request, $appointment),
                'rejected' => $this->processTerminal(
                    $appointment, AppointmentStatusEnum::REJECTED,
                    AppointmentEventTypeEnum::REJECTED, 'El SAT rechazó la cita.',
                ),
                'no_show' => $this->processTerminal(
                    $appointment, AppointmentStatusEnum::NO_SHOW,
                    AppointmentEventTypeEnum::NO_SHOW, 'El soldado no se presentó a la cita.',
                ),
            };
        } catch (\Throwable $th) {
            Log::error('SAT bot callback: failed to process result.', [
                'appointment_id' => $appointment->id,
                'status' => $payload['status'],
                'exception' => $th->getMessage(),
            ]);

            return response()->json(['error' => 'Processing failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json(['message' => 'Appointment updated.'], Response::HTTP_OK);
    }

    /**
     * Apply a terminal status (rejected / no_show) and leave it on the timeline.
     *
     * @param  Appointment  $appointment  The appointment being closed.
     * @param  AppointmentStatusEnum  $status  The status to set.
     * @param  AppointmentEventTypeEnum  $event  The timeline entry to append.
     * @param  string  $description  Human-readable summary for the timeline.
     */
    private function processTerminal(
        Appointment $appointment,
        AppointmentStatusEnum $status,
        AppointmentEventTypeEnum $event,
        string $description,
    ): void {
        $appointment->update(['status' => $status, 'last_review_at' => now()]);
        $appointment->recordEvent($event, $description);
    }

    /**
     * Tell the team what happened, without ever breaking the callback.
     *
     * The appointment is already saved by the time this runs, so a notification problem
     * (misconfigured recipients, mail transport down) must not turn a successful
     * callback into a 500 — the bot would treat the update as failed and report it all
     * over again.
     *
     * @param  NotificationEventEnum  $event  The configurable event that fired.
     * @param  Appointment  $appointment  The appointment the bot reported on.
     * @param  string  $outcome  formed | scheduled | failed.
     * @param  string|null  $reason  Failure detail, when relevant.
     */
    private function notifyTeam(
        NotificationEventEnum $event,
        Appointment $appointment,
        string $outcome,
        ?string $reason = null,
    ): void {
        try {
            app(EventNotifier::class)->notify(
                $event,
                new SatAppointmentStatusNotification($appointment, $outcome, $reason),
            );
        } catch (\Throwable $th) {
            Log::warning('SAT bot callback: could not notify the team.', [
                'appointment_id' => $appointment->id,
                'event' => $event->value,
                'exception' => $th->getMessage(),
            ]);
        }
    }

    /**
     * Apply a formed outcome: the bot queued the appointment in the SAT virtual queue.
     *
     * The pool alias stays locked — the SAT sends the token there and the bot needs it on
     * every review. It is released when the appointment reaches `scheduled`. `office`
     * carries the SAT branch the bot picked, when it reports one.
     *
     * @param  Request  $request  Callback request, optionally carrying the chosen office.
     * @param  Appointment  $appointment  The appointment that was formed.
     */
    private function processFormed(Request $request, Appointment $appointment): void
    {
        $attributes = [
            'status' => AppointmentStatusEnum::FORMED,
            'formed_at' => now(),
            'last_review_at' => now(),
        ];

        if (filled($request->input('office'))) {
            $attributes['office'] = (string) $request->input('office');
        }

        $appointment->update($attributes);

        // El correo ya quedó registrado en el SAT (fila virtual): arranca su cooldown. No
        // hay fecha aún, así que el reloj corre desde ahora (se afina a scheduled_at cuando
        // el SAT asigne la cita, en processScheduled).
        if (filled($appointment->email_alias)) {
            AppointmentEmail::query()
                ->where('address', $appointment->email_alias)
                ->update(['last_used_at' => now()]);
        }

        $appointment->recordEvent(
            AppointmentEventTypeEnum::FORMED,
            'El bot formó la cita en la fila virtual'
                .(filled($appointment->office) ? " (sucursal {$appointment->office})" : '').'.',
            ['office' => $appointment->office, 'email_alias' => $appointment->email_alias],
        );

        $this->notifyTeam(NotificationEventEnum::SAT_APPOINTMENT_FORMED, $appointment, 'formed');

        Log::info('SAT bot callback: appointment formed in the virtual queue.', [
            'appointment_id' => $appointment->id,
            'office' => $appointment->office,
            'email_alias' => $appointment->email_alias,
        ]);
    }

    /**
     * Apply a scheduled outcome: save date/office/acuse, free the alias, notify.
     *
     * @param  Request  $request  Callback request with scheduling fields.
     * @param  Appointment  $appointment  The appointment being resolved.
     */
    private function processScheduled(Request $request, Appointment $appointment): void
    {
        $attributes = [
            'status' => AppointmentStatusEnum::SCHEDULED,
            'office' => $request->input('office') ?: $appointment->office,
            'last_review_at' => now(),
        ];

        if (filled($request->input('scheduled_at'))) {
            $attributes['scheduled_at'] = Carbon::parse((string) $request->input('scheduled_at'));
        }

        $acuse = $request->input('acuse_pdf_base64');

        if (filled($acuse)) {
            $content = base64_decode((string) $acuse, strict: true);

            if ($content === false) {
                throw new \RuntimeException('Invalid base64 acuse PDF.');
            }

            $path = "appointments/acuses/{$appointment->id}.pdf";
            Storage::disk(config('filesystems.default'))->put($path, $content);
            $attributes['acknowledgment_path'] = $path;
        }

        $appointment->update($attributes);

        // NO liberar el correo aquí. La cita ya tiene fecha pero AÚN NO PASA. Afinamos el
        // reloj de cooldown a la fecha de la cita: el correo sigue quemado en el SAT hasta el
        // día de la cita y se libera 24h después (claimFor lo respeta por last_used_at). El
        // check por appointment ($blocked) lo cubre mientras la fecha sea futura; last_used_at
        // sostiene el cooldown durante las 24h posteriores.
        if (filled($appointment->email_alias) && $appointment->scheduled_at !== null) {
            AppointmentEmail::query()
                ->where('address', $appointment->email_alias)
                ->update(['last_used_at' => $appointment->scheduled_at]);
        }

        $this->notifySoldado($appointment);

        $appointment->recordEvent(
            AppointmentEventTypeEnum::SCHEDULED,
            'El SAT asignó fecha y hora: '
                .($appointment->scheduled_at?->format('d/m/Y H:i') ?? 'sin fecha').'.',
            [
                'scheduled_at' => $appointment->scheduled_at?->toDateTimeString(),
                'office' => $appointment->office,
                'acuse' => filled($appointment->acknowledgment_path),
            ],
        );

        // Y al equipo, para que sepan que ya hay fecha sin revisar el panel.
        $this->notifyTeam(NotificationEventEnum::SAT_APPOINTMENT_SCHEDULED, $appointment, 'scheduled');

        $this->flagSoldadoOverlap($appointment);

        Log::info('SAT bot callback: appointment scheduled.', [
            'appointment_id' => $appointment->id,
            'scheduled_at' => $appointment->scheduled_at?->toDateTimeString(),
        ]);
    }

    /**
     * Detect and flag when the same soldado ends up with two citas too close in time.
     *
     * A soldado cannot attend two SAT citas at once. The SAT assigns the slot, so we can't
     * prevent it up front — but when a scheduled cita lands within 2 hours of another cita
     * of the SAME soldado, we record it on the timeline and alert the team so they can
     * reassign one of them (only they can, per the acta's apoderados).
     *
     * @param  Appointment  $appointment  The just-scheduled appointment.
     */
    private function flagSoldadoOverlap(Appointment $appointment): void
    {
        if ($appointment->soldado_id === null || $appointment->scheduled_at === null) {
            return;
        }

        $conflict = Appointment::query()
            ->whereKeyNot($appointment->id)
            ->where('soldado_id', $appointment->soldado_id)
            ->where('status', AppointmentStatusEnum::SCHEDULED->value)
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [
                $appointment->scheduled_at->copy()->subHours(2),
                $appointment->scheduled_at->copy()->addHours(2),
            ])
            ->first();

        if ($conflict === null) {
            return;
        }

        $appointment->recordEvent(
            AppointmentEventTypeEnum::SCHEDULED,
            '⚠️ Traslape: este soldado tiene otra cita ('.($conflict->registration?->primaryLegalName?->name ?? $conflict->id)
                .') el '.($conflict->scheduled_at?->format('d/m/Y H:i') ?? '—').'. No puede ir a las dos — reasigna una.',
            ['conflict_appointment_id' => $conflict->id, 'conflict_at' => $conflict->scheduled_at?->toDateTimeString()],
        );

        $this->notifyTeam(NotificationEventEnum::SAT_APPOINTMENT_FAILED, $appointment, 'failed',
            'Traslape de soldado: mismo soldado con dos citas a menos de 2 horas. Reasigna una.');

        Log::warning('SAT bot callback: soldado overlap detected.', [
            'appointment_id' => $appointment->id,
            'conflict_appointment_id' => $conflict->id,
            'soldado_id' => $appointment->soldado_id,
        ]);
    }

    /**
     * Record a review with no slot yet: bump last_review_at, keep the appointment formed.
     *
     * @param  Appointment  $appointment  The appointment that was reviewed.
     */
    private function processInReview(Appointment $appointment): void
    {
        $appointment->update(['last_review_at' => now()]);

        $appointment->recordEvent(
            AppointmentEventTypeEnum::REVIEWED,
            'El bot revisó el SAT: el turno sigue en la fila, sin fecha asignada.',
        );

        Log::info('SAT bot callback: appointment reviewed, still no slot.', [
            'appointment_id' => $appointment->id,
        ]);
    }

    /**
     * Apply a failure: record the reason and leave the status untouched so it retries.
     *
     * Works for both phases — a `pending_forming` appointment stays pending_forming and a
     * `formed` one stays formed, so the next poll picks it up again. The alias is kept in
     * both cases: reusing it keeps the retry idempotent instead of draining the pool.
     *
     * @param  Request  $request  Callback request with failure_reason.
     * @param  Appointment  $appointment  The appointment that could not be processed.
     */
    private function processFailure(Request $request, Appointment $appointment): void
    {
        $reason = (string) $request->input('failure_reason', 'Sin detalle.');
        $stamp = now()->toDateTimeString();
        $phase = $appointment->status === AppointmentStatusEnum::PENDING_FORMING ? 'formar' : 'revisar';
        $note = trim(($appointment->notes ? $appointment->notes."\n" : '')."[{$stamp}] Bot SAT: fallo al {$phase} — {$reason}");

        $appointment->update([
            'notes' => $note,
            'last_review_at' => now(),
        ]);

        $appointment->recordEvent(
            AppointmentEventTypeEnum::FAILED,
            "El bot no pudo {$phase} la cita: {$reason}",
            ['phase' => $phase, 'reason' => $reason],
        );

        // Si el fallo fue al FORMAR, el correo ya quedó registrado (quemado) en el SAT —
        // reintentar con el mismo da error_on_correo_repetido. Lo soltamos: el reintento
        // pedirá uno fresco y el quemado queda en cooldown 24h (AppointmentEmail::claimFor).
        // En fallos al REVISAR no se toca: la cita sigue formada en ese buzón.
        if ($phase === 'formar') {
            AppointmentEmail::releaseBurnedAlias($appointment);
        }

        $this->notifyTeam(NotificationEventEnum::SAT_APPOINTMENT_FAILED, $appointment, 'failed', $reason);

        Log::warning('SAT bot callback: step failed.', [
            'appointment_id' => $appointment->id,
            'phase' => $phase,
            'reason' => $reason,
        ]);
    }

    /**
     * Free the pool email assigned to this appointment (keep the record on the appointment).
     *
     * @param  Appointment  $appointment  The appointment whose alias is freed.
     */
    private function releaseAlias(Appointment $appointment): void
    {
        if (blank($appointment->email_alias)) {
            return;
        }

        AppointmentEmail::where('address', $appointment->email_alias)->update(['is_free' => true]);
    }

    /**
     * Notify the soldado of the scheduled appointment (email now; WhatsApp via the
     * notification's channels once a provider is wired).
     *
     * @param  Appointment  $appointment  The scheduled appointment.
     */
    private function notifySoldado(Appointment $appointment): void
    {
        $soldado = $appointment->soldado;

        if ($soldado === null) {
            return;
        }

        $notification = new SatAppointmentScheduledNotification($appointment);

        try {
            // Queued: this only pushes to the queue (retries + failure logging live in
            // the notification). The appointment is already saved, so a dispatch hiccup
            // must not turn the callback into a 500 and make the bot think it failed.
            if ($soldado->user !== null) {
                $soldado->user->notify($notification);
            } elseif (filled($soldado->email)) {
                Notification::route('mail', $soldado->email)->notify($notification);
            }
        } catch (\Throwable $th) {
            Log::warning('SAT bot callback: could not queue the soldado notification.', [
                'appointment_id' => $appointment->id,
                'soldado_id' => $appointment->soldado_id,
                'exception' => $th->getMessage(),
            ]);
        }
    }

    /**
     * Verify the HMAC-SHA256 signature of the request.
     *
     * Keys are sorted alphabetically before encoding to produce a canonical payload —
     * the bot must apply the same sorting (see docs/CONTRACT.md).
     *
     * @param  array<string, mixed>  $payload  Extracted fields to sign.
     * @param  string  $signature  HMAC hex digest from the X-Signature header.
     */
    private function isValidSignature(array $payload, string $signature): bool
    {
        ksort($payload);
        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $expected = hash_hmac('sha256', $canonical, (string) config('services.sat_bot.secret_key'));

        return hash_equals($expected, $signature);
    }
}
