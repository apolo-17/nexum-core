<?php

declare(strict_types=1);

namespace App\Services\Sat;

use App\Enums\AppointmentTypeEnum;
use App\Models\Appointment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * On-demand "check status now" against the SAT bot.
 *
 * Unlike forming (fire-and-forget), this is synchronous on purpose: the operator clicks
 * "Revisar status ahora" and waits a few seconds for the live answer. The bot checks the
 * mailbox (and the SAT, if there is an assignment) and reports the outcome through the
 * webhook — this call just also returns it so the panel can show it immediately.
 */
class SatReviewService
{
    /**
     * Ask the bot to review one appointment right now and return the outcome.
     *
     * @param  Appointment  $appointment  A formed appointment to check.
     * @return array{status:string, message:string} Outcome for the UI (never throws).
     */
    public function reviewNow(Appointment $appointment): array
    {
        $botUrl = rtrim((string) config('services.sat_bot.url'), '/');
        $apiKey = (string) config('services.sat_bot.api_key');

        if ($botUrl === '' || $apiKey === '') {
            return ['status' => 'error', 'message' => 'El bot no está configurado (falta URL o API key).'];
        }

        if (blank($appointment->email_alias)) {
            return ['status' => 'error', 'message' => 'La cita no tiene correo del pool asignado.'];
        }

        $appointment->loadMissing(['registration', 'soldado']);
        $registration = $appointment->registration;
        $soldado = $appointment->soldado;

        $payload = [
            'appointment_id' => $appointment->id,
            'sat_service' => $appointment->type === AppointmentTypeEnum::RFC ? 'PM' : 'E',
            'company' => [
                'rfc' => $registration?->rfc,
                'razon_social' => $registration?->primaryLegalName?->name
                    ?? $registration?->singapur_folder_name,
            ],
            'soldado' => ['rfc' => $soldado?->rfc],
            'email_alias' => $appointment->email_alias,
        ];

        try {
            // A live review can take a while (email code + SAT round-trips), so give it
            // room — the operator is waiting for this answer on purpose.
            $response = Http::connectTimeout(10)
                ->timeout(90)
                ->withHeader('X-Bot-Api-Key', $apiKey)
                ->post("{$botUrl}/review", $payload)
                ->throw()
                ->json();

            return [
                'status' => (string) ($response['status'] ?? 'error'),
                'message' => (string) ($response['message'] ?? 'Sin detalle.'),
            ];
        } catch (\Throwable $exception) {
            Log::error('SatReviewService: live review failed.', [
                'appointment_id' => $appointment->id,
                'error' => $exception->getMessage(),
            ]);

            return ['status' => 'error', 'message' => 'No se pudo consultar el bot. Intenta de nuevo en un momento.'];
        }
    }
}
