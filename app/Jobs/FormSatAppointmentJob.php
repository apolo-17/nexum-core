<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AppointmentStatusEnum;
use App\Models\Appointment;
use App\Services\Sat\SatFormingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Pushes a SAT appointment to the bot to be formed, in the background.
 *
 * The bot works synchronously (~1 min): session, email code, form in the virtual queue.
 * Doing that from the Filament request blocked the modal, so it runs here instead — the
 * button dispatches this job and returns immediately. The real outcome arrives through
 * the signed webhook, which notifies the team. Mirrors SubmitLegalNameToMuaJob.
 */
class FormSatAppointmentJob implements ShouldQueue
{
    use Queueable;

    /**
     * Only attempt once: a failed dispatch leaves the appointment pending_forming and the
     * front-end button (or a resend) handles retries. No silent auto-retry loop.
     */
    public int $tries = 1;

    /**
     * @param  string  $appointmentId  ULID of the appointment to form.
     */
    public function __construct(
        private readonly string $appointmentId,
    ) {}

    /**
     * Execute the job — push the appointment to the SAT bot.
     *
     * Aborts early if the appointment is gone or no longer pending_forming (e.g. it was
     * already formed between dispatch and execution).
     *
     * @param  SatFormingService  $formingService  Injected forming service.
     */
    public function handle(SatFormingService $formingService): void
    {
        $appointment = Appointment::find($this->appointmentId);

        if ($appointment === null) {
            Log::warning('FormSatAppointmentJob: appointment not found — skipping.', [
                'appointment_id' => $this->appointmentId,
            ]);

            return;
        }

        if ($appointment->status !== AppointmentStatusEnum::PENDING_FORMING) {
            Log::debug('FormSatAppointmentJob: appointment no longer pending_forming — skipping.', [
                'appointment_id' => $this->appointmentId,
                'status' => $appointment->status->value,
            ]);

            return;
        }

        try {
            $formingService->dispatchToBot($appointment);
        } catch (\Throwable $exception) {
            // Do not re-throw: the appointment stays pending_forming and can be resent
            // from the panel. The failure is recorded on the timeline by the service.
            Log::error('FormSatAppointmentJob: forming dispatch failed.', [
                'appointment_id' => $this->appointmentId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
