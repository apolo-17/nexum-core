<?php

declare(strict_types=1);

namespace App\Services\Sat;

use App\Enums\AppointmentEventTypeEnum;
use App\Enums\AppointmentStatusEnum;
use App\Enums\AppointmentTypeEnum;
use App\Models\Appointment;
use App\Models\AppointmentEmail;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pushes an appointment to the nexum-citas-sat bot so it forms it in the virtual queue.
 *
 * Mirrors MuaSubmissionService: Nexum dispatches the work and the bot reports the real
 * outcome through the signed webhook, which is the source of truth. The bot works
 * synchronously (session + email code + form, ~1 min), so a read timeout here is the
 * EXPECTED, safe path — the appointment stays pending_forming and the callback finishes
 * the job.
 *
 * Nexum owns the email pool: this service locks a pool address before dispatching, so
 * the SAT sends the confirmation code somewhere the bot can read.
 */
class SatFormingService
{
    /**
     * Send an appointment to the bot to be formed.
     *
     * @param  Appointment  $appointment  An appointment in pending_forming with a soldado.
     * @return bool True when the bot accepted the dispatch (or is still working on it).
     */
    public function dispatchToBot(Appointment $appointment): bool
    {
        if ($appointment->status !== AppointmentStatusEnum::PENDING_FORMING) {
            Log::warning('SatFormingService: appointment is not pending_forming.', [
                'appointment_id' => $appointment->id,
                'status' => $appointment->status->value,
            ]);

            return false;
        }

        if ($appointment->soldado_id === null) {
            Log::warning('SatFormingService: appointment has no soldado assigned.', [
                'appointment_id' => $appointment->id,
            ]);

            return false;
        }

        // Validar la configuración ANTES de tocar el pool: si falta la URL o la llave no
        // hay a dónde mandar la cita, y bloquear un correo aquí lo dejaría inservible
        // para las demás sin haber intentado nada.
        $botUrl = rtrim((string) config('services.sat_bot.url'), '/');
        $apiKey = (string) config('services.sat_bot.api_key');

        if ($botUrl === '' || $apiKey === '') {
            Log::error('SatFormingService: SAT bot url/api key not configured.', [
                'appointment_id' => $appointment->id,
                'has_url' => $botUrl !== '',
                'has_api_key' => $apiKey !== '',
            ]);

            return false;
        }

        $alias = $this->assignAlias($appointment);

        if ($alias === null) {
            Log::warning('SatFormingService: no free pool address available.', [
                'appointment_id' => $appointment->id,
            ]);

            return false;
        }

        $appointment->loadMissing(['registration.primaryLegalName', 'soldado']);
        $registration = $appointment->registration;
        $soldado = $appointment->soldado;

        $payload = [
            'appointment_id' => $appointment->id,
            'type' => $appointment->type->value,
            'sat_service' => $appointment->type === AppointmentTypeEnum::RFC ? 'PM' : 'E',
            'company' => [
                'registration_id' => $registration?->id,
                'rfc' => $registration?->rfc,
                'razon_social' => $registration?->primaryLegalName?->name
                    ?? $registration?->singapur_folder_name,
            ],
            'soldado' => [
                'id' => $soldado?->id,
                'name' => $soldado?->name,
                'curp' => $soldado?->curp,
                'rfc' => $soldado?->rfc,
                'email' => $soldado?->email,
                'phone' => $soldado?->phone,
            ],
            'email_alias' => $alias,
            'entidad' => '10', // CDMX en el catálogo del SAT
            'preferred_module' => $appointment->preferred_module,
        ];

        try {
            // Only the RECEIPT matters. The bot then works ~1 min and reports via the
            // webhook. A short timeout frees the PHP worker fast; the ConnectionException
            // below is the expected path, not an error.
            Http::connectTimeout(10)
                ->timeout(30)
                ->withHeader('X-Bot-Api-Key', $apiKey)
                ->post("{$botUrl}/form", $payload)
                ->throw();

            $appointment->recordEvent(
                AppointmentEventTypeEnum::FORM_DISPATCHED,
                "Enviada al bot para formar con el correo {$alias}."
                    .($appointment->preferred_module ? " Sucursal pedida: {$appointment->preferred_module}." : ''),
                ['email_alias' => $alias, 'preferred_module' => $appointment->preferred_module],
            );

            Log::info('SatFormingService: appointment dispatched to SAT bot.', [
                'appointment_id' => $appointment->id,
                'email_alias' => $alias,
                'preferred_module' => $appointment->preferred_module,
            ]);

            return true;
        } catch (ConnectionException $exception) {
            // Expected: the synchronous bot outlives our timeout. It keeps working and
            // reports through the webhook, so we leave the appointment as it is.
            $appointment->recordEvent(
                AppointmentEventTypeEnum::FORM_DISPATCHED,
                "Enviada al bot para formar con el correo {$alias}. El bot sigue trabajando; "
                    .'el resultado llegará por el webhook.',
                ['email_alias' => $alias, 'preferred_module' => $appointment->preferred_module],
            );

            Log::warning('SatFormingService: bot /form timed out — the webhook will finalize.', [
                'appointment_id' => $appointment->id,
                'error' => $exception->getMessage(),
            ]);

            return true;
        } catch (RequestException $exception) {
            // The bot rejected the dispatch outright: no webhook will follow. Free the
            // alias so the appointment can be resent cleanly.
            $this->releaseAlias($appointment);

            $appointment->recordEvent(
                AppointmentEventTypeEnum::FAILED,
                'El bot rechazó el envío ('.$exception->response->status().'). Se liberó el correo.',
                ['status' => $exception->response->status()],
            );

            Log::error('SatFormingService: bot rejected the dispatch.', [
                'appointment_id' => $appointment->id,
                'status' => $exception->response->status(),
                'body' => $exception->response->body(),
            ]);

            return false;
        }
    }

    /**
     * Assign (or reuse) a locked pool address for this appointment.
     *
     * Reusing an already-assigned alias keeps resends idempotent: a retried appointment
     * does not consume a second address.
     *
     * @param  Appointment  $appointment  The appointment to give a mailbox to.
     * @return string|null The pool address, or null when the pool is exhausted.
     */
    private function assignAlias(Appointment $appointment): ?string
    {
        if (filled($appointment->email_alias)) {
            return $appointment->email_alias;
        }

        return DB::transaction(function () use ($appointment): ?string {
            $email = AppointmentEmail::where('is_free', true)
                ->lockForUpdate()
                ->orderBy('address')
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
     * Return the pool address to circulation after a rejected dispatch.
     *
     * @param  Appointment  $appointment  The appointment whose alias is freed.
     */
    private function releaseAlias(Appointment $appointment): void
    {
        if (blank($appointment->email_alias)) {
            return;
        }

        AppointmentEmail::where('address', $appointment->email_alias)->update(['is_free' => true]);
        $appointment->update(['email_alias' => null]);
    }
}
