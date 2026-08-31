<?php

namespace App\Observers;

use App\Enums\AppointmentStatusEnum;
use App\Jobs\FormSatAppointmentJob;
use App\Models\Appointment;

/**
 * Auto-forms a SAT appointment the moment it is created — no matter where it was created
 * (expediente, dashboard, the soldado's mobile flow, or the API). Before, only the expediente's
 * create action queued the forming; a cita created elsewhere sat in "Por formar" forever.
 *
 * Guarded to a pending_forming cita that already has a soldado (the bot needs who to queue).
 * FormSatAppointmentJob re-checks the status, so this never re-forms an already-formed cita.
 */
class AppointmentObserver
{
    public function created(Appointment $appointment): void
    {
        // Solo si el bot está configurado (en pruebas no lo está, así que no intenta formar).
        if (blank(config('services.sat_bot.url'))) {
            return;
        }

        if ($appointment->status !== AppointmentStatusEnum::PENDING_FORMING || $appointment->soldado_id === null) {
            return;
        }

        FormSatAppointmentJob::dispatch($appointment->id);
    }
}
