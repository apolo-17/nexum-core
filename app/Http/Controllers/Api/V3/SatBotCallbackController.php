<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V3;

use App\Enums\AppointmentStatusEnum;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentEmail;
use App\Notifications\SatAppointmentScheduledNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives callbacks from the nexum-citas-sat bot reviewing a FORMED SAT appointment.
 *
 * The appointment was formed MANUALLY by the team; the bot only monitors it. Auth:
 * HMAC-SHA256 over {appointment_id, status, timestamp} in the X-Signature header
 * (identical scheme to MuaBotCallbackController). Statuses the bot reports:
 *
 *  - scheduled : SAT assigned a slot → fill date/office/acuse → R2, free the alias, notify.
 *  - in_review : checked, still no slot → bump last_review_at, stay formed.
 *  - rejected  : SAT rejected the appointment → mark rejected.
 *  - no_show   : the soldado did not show up → mark no_show.
 *  - failed    : the bot could not review (transient error) → note it, stay formed to retry.
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
                'scheduled' => $this->processScheduled($request, $appointment),
                'in_review' => $this->processInReview($appointment),
                'failed' => $this->processFailure($request, $appointment),
                'rejected' => $appointment->update([
                    'status' => AppointmentStatusEnum::REJECTED,
                    'last_review_at' => now(),
                ]),
                'no_show' => $appointment->update([
                    'status' => AppointmentStatusEnum::NO_SHOW,
                    'last_review_at' => now(),
                ]),
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

        // Free the pool email for reuse (keep email_alias on the appointment as a record).
        $this->releaseAlias($appointment);

        $this->notifySoldado($appointment);

        Log::info('SAT bot callback: appointment scheduled.', [
            'appointment_id' => $appointment->id,
            'scheduled_at' => $appointment->scheduled_at?->toDateTimeString(),
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

        Log::info('SAT bot callback: appointment reviewed, still no slot.', [
            'appointment_id' => $appointment->id,
        ]);
    }

    /**
     * Apply a failed review: record the reason, keep it formed so the next poll retries.
     *
     * @param  Request  $request  Callback request with failure_reason.
     * @param  Appointment  $appointment  The appointment that could not be reviewed.
     */
    private function processFailure(Request $request, Appointment $appointment): void
    {
        $reason = (string) $request->input('failure_reason', 'Sin detalle.');
        $stamp = now()->toDateTimeString();
        $note = trim(($appointment->notes ? $appointment->notes."\n" : '')."[{$stamp}] Bot SAT: fallo al revisar — {$reason}");

        // Status stays FORMED so the next review retries it. The alias was set manually, keep it.
        $appointment->update([
            'notes' => $note,
            'last_review_at' => now(),
        ]);

        Log::warning('SAT bot callback: review failed.', [
            'appointment_id' => $appointment->id,
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

        if ($soldado->user !== null) {
            $soldado->user->notify($notification);
        } elseif (filled($soldado->email)) {
            Notification::route('mail', $soldado->email)->notify($notification);
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
