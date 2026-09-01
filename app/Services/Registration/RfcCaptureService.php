<?php

declare(strict_types=1);

namespace App\Services\Registration;

use App\Enums\AppointmentEventTypeEnum;
use App\Enums\AppointmentStatusEnum;
use App\Enums\AppointmentTypeEnum;
use App\Enums\RegistrationStageEnum;
use App\Models\Appointment;
use Illuminate\Support\Facades\Log;

/**
 * Shared RFC-capture flow used by BOTH the soldado (mobile / Mis Citas) and the admin, so the
 * two never diverge. After a successful RFC cita, the operator just types the company RFC — no
 * photo/scan (those were not accepted anyway). From that single RFC we:
 *
 *   1. Save the company RFC on the expediente.
 *   2. Mark the RFC cita as attended.
 *   3. Link the soldado as legal representative (so they can attend the e.firma cita).
 *   4. Advance the pipeline to the e.firma stage (the RFC milestone is met).
 *
 * Forming the e.firma cita is a separate call (formEfirma) so the caller decides WHEN: the admin
 * forms it immediately without asking; the soldado only after they confirm they will attend.
 * Creating the e.firma cita auto-dispatches its forming (AppointmentObserver), so it goes straight
 * to "Formada (por revisar)" instead of sitting in "Por formar".
 */
class RfcCaptureService
{
    public function __construct(private readonly StageTransitionService $stages) {}

    /**
     * Record the RFC from a completed RFC cita and mark it attended.
     *
     * @param  Appointment  $rfcCita  The RFC (inscripción) appointment that was attended.
     * @param  string  $rfc  The company RFC as typed by the soldado/admin.
     * @param  string  $actor  Who did it ('soldado' | 'user') for the timeline.
     */
    public function captureRfc(Appointment $rfcCita, string $rfc, string $actor = 'user'): void
    {
        $rfc = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $rfc));
        $registration = $rfcCita->registration;

        if ($registration === null || $rfc === '') {
            return;
        }

        $registration->update(['rfc' => $rfc]);

        $rfcCita->update(['status' => AppointmentStatusEnum::ATTENDED]);
        $rfcCita->recordEvent(
            AppointmentEventTypeEnum::ATTENDED,
            "Cita de RFC completada. RFC capturado: {$rfc}.",
            ['rfc' => $rfc],
            $actor,
        );

        // Ligar al soldado como representante legal (para el selector/forma de la e.firma).
        if ($rfcCita->soldado_id !== null && ! $registration->soldados()->where('soldados.id', $rfcCita->soldado_id)->exists()) {
            $registration->soldados()->attach($rfcCita->soldado_id, ['role' => 'legal_representative']);
        }

        // Con el RFC ya en el expediente, el hito de inscripción está cumplido: avanza la etapa.
        try {
            $this->stages->jumpTo(
                $registration->fresh(),
                RegistrationStageEnum::EFIRMA_APPOINTMENT,
                null,
                'Avance automático: RFC de la empresa capturado.',
            );
        } catch (\Throwable $e) {
            Log::warning('RfcCaptureService: no se pudo avanzar la etapa.', [
                'registration_id' => $registration->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create the e.firma (FIEL) cita for the same company/soldado and send it to be formed at the
     * SAT immediately (via AppointmentObserver on create). Reuses an existing live FIEL cita.
     *
     * @param  Appointment  $rfcCita  The RFC cita whose company/soldado seeds the e.firma cita.
     * @return Appointment The e.firma appointment (existing or newly created).
     */
    public function formEfirma(Appointment $rfcCita): Appointment
    {
        $registration = $rfcCita->registration;

        $fiel = $registration->appointments()
            ->where('type', AppointmentTypeEnum::FIEL->value)
            ->whereNotIn('status', [AppointmentStatusEnum::CANCELLED->value, AppointmentStatusEnum::REJECTED->value])
            ->first();

        // Crear la e.firma dispara su formado automático (AppointmentObserver): va directo a
        // "Formada (por revisar)", no se queda en "Por formar".
        return $fiel ?? $registration->appointments()->create([
            'type' => AppointmentTypeEnum::FIEL->value,
            'status' => AppointmentStatusEnum::PENDING_FORMING->value,
            'soldado_id' => $rfcCita->soldado_id,
        ]);
    }
}
