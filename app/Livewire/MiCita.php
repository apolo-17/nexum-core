<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\AppointmentEventTypeEnum;
use App\Enums\AppointmentStatusEnum;
use App\Enums\AppointmentTypeEnum;
use App\Models\Appointment;
use App\Models\Soldado;
use App\Models\User;
use App\Notifications\SoldadoCitaUpdateNotification;
use App\Services\Registration\RfcCaptureService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Mobile self-service flow for a soldado right after their SAT cita.
 *
 * Full-screen, low-noise: the soldado opens it on their phone, says how the cita went,
 * and (if it went well) photographs the CSF. Claude reads the company RFC from the photo;
 * the soldado confirms it; we store the CSF + RFC and, if they will attend the e.firma
 * cita too, form it automatically in the background.
 */
#[Layout('components.layouts.mobile')]
class MiCita extends Component
{
    /** Steps: none | status | reject | rfc | efirma | done */
    public string $step = 'status';

    /** Motivo que da el soldado cuando el SAT rechazó la cita. */
    public string $rejectionReason = '';

    public ?string $citaId = null;

    public string $empresa = '';

    /** The company RFC the soldado types in (no photo/scan anymore). */
    public ?string $rfc = null;

    public string $doneTitle = '';

    public string $doneBody = '';

    public function mount(): void
    {
        $soldado = Soldado::where('user_id', Auth::id())->first();

        if ($soldado === null) {
            $this->step = 'none';

            return;
        }

        // A cita that already happened (>30 min ago, in CDMX time) and still has no result.
        $cita = $soldado->appointments()
            ->where('type', AppointmentTypeEnum::RFC->value)
            ->where('status', AppointmentStatusEnum::SCHEDULED->value)
            ->whereNotNull('scheduled_at')
            ->with('registration.primaryLegalName')
            ->orderBy('scheduled_at')
            ->get()
            ->first(fn (Appointment $a): bool => self::yaPaso($a));

        if ($cita === null) {
            $this->step = 'none';

            return;
        }

        $this->citaId = $cita->id;
        $this->empresa = $cita->registration?->primaryLegalName?->name
            ?? $cita->registration?->singapur_folder_name
            ?? 'tu empresa';
    }

    /**
     * scheduled_at is stored as the SAT's CDMX wall-clock; reinterpret it in CDMX and
     * check it is at least 30 minutes in the past.
     */
    private static function yaPaso(Appointment $a): bool
    {
        if ($a->scheduled_at === null) {
            return false;
        }

        $cdmx = Carbon::parse($a->scheduled_at->format('Y-m-d H:i:s'), 'America/Mexico_City');

        return $cdmx->addMinutes(30)->isPast();
    }

    private function cita(): ?Appointment
    {
        return $this->citaId !== null
            ? Appointment::with('registration')->find($this->citaId)
            : null;
    }

    // --- Paso 1: ¿cómo te fue? ---

    public function marcar(string $resultado): void
    {
        $cita = $this->cita();

        if ($cita === null) {
            return;
        }

        if ($resultado === 'attended') {
            $this->step = 'rfc';

            return;
        }

        // Rechazada: pedir el motivo antes de cerrar (por qué la rechazó el SAT).
        if ($resultado === 'rejected') {
            $this->step = 'reject';

            return;
        }

        // No asistió: se marca y termina (sin foto).
        $cita->update(['status' => AppointmentStatusEnum::NO_SHOW]);
        $cita->recordEvent(AppointmentEventTypeEnum::NO_SHOW, 'El soldado reportó que no asistió.', [], 'soldado');
        $this->doneTitle = 'Registrado';
        $this->doneBody = 'Gracias por avisar.';

        $this->notifyAdmins("El soldado marcó su cita de RFC de {$this->empresa} como: no asistió.");
        $this->step = 'done';
    }

    /**
     * Confirmar el rechazo con el motivo que dio el soldado (por qué el SAT rechazó el trámite).
     */
    public function confirmarRechazo(): void
    {
        $motivo = trim($this->rejectionReason);

        if ($motivo === '') {
            $this->addError('rejectionReason', 'Escribe el motivo del rechazo.');

            return;
        }

        $cita = $this->cita();
        if ($cita === null) {
            return;
        }

        $cita->update([
            'status' => AppointmentStatusEnum::REJECTED,
            'rejection_reason' => $motivo,
        ]);
        $cita->recordEvent(
            AppointmentEventTypeEnum::REJECTED,
            'El soldado reportó que el SAT rechazó el trámite. Motivo: '.$motivo,
            ['rejection_reason' => $motivo],
            'soldado',
        );

        $this->notifyAdmins("El soldado marcó su cita de RFC de {$this->empresa} como RECHAZADA. Motivo: {$motivo}");
        $this->doneTitle = 'Registrado';
        $this->doneBody = 'Gracias por avisar. El equipo sacará una nueva cita.';
        $this->step = 'done';
    }

    // --- Paso 2: capturar el RFC (solo texto, sin foto) ---

    public function confirmar(): void
    {
        $this->validate([
            'rfc' => 'required|string|min:12|max:13',
        ], [
            'rfc.required' => 'Escribe el RFC.',
            'rfc.min' => 'El RFC de una empresa tiene 12 caracteres.',
        ]);

        $cita = $this->cita();

        if ($cita === null || $cita->registration === null) {
            return;
        }

        // Mismo servicio que usa el admin: guarda el RFC, marca asistida y liga al soldado.
        app(RfcCaptureService::class)->captureRfc($cita, (string) $this->rfc, 'soldado');

        $this->rfc = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', (string) $this->rfc));
        $this->notifyAdmins("El soldado completó la cita de RFC de {$this->empresa}. RFC capturado: {$this->rfc}.");

        $this->step = 'efirma';
    }

    // --- Paso 3: ¿vas a la de e.firma? → se forma sola en ese momento ---

    public function efirma(bool $va): void
    {
        $cita = $this->cita();

        if ($va && $cita !== null) {
            // Crea la e.firma y la manda a formar al SAT de inmediato (AppointmentObserver):
            // va directo a "Formada (por revisar)", no se queda en "Por formar".
            app(RfcCaptureService::class)->formEfirma($cita);

            $this->doneTitle = '¡Listo! 🚀';
            $this->doneBody = 'Ya se está formando tu cita de e.firma de '.$this->empresa.'. Te avisamos por correo cuando el SAT le dé fecha.';
        } else {
            $this->doneTitle = '¡Listo! ✅';
            $this->doneBody = 'Guardamos el RFC. Gracias.';
        }

        $this->step = 'done';
    }

    private function notifyAdmins(string $body): void
    {
        try {
            $admins = User::role('super_admin')->get();

            if ($admins->isEmpty()) {
                return;
            }

            NotificationFacade::send($admins, new SoldadoCitaUpdateNotification($this->empresa, $body));
        } catch (\Throwable $e) {
            Log::warning('MiCita: no se pudo avisar a los admins', ['error' => $e->getMessage()]);
        }
    }

    public function render()
    {
        return view('livewire.mi-cita');
    }
}
