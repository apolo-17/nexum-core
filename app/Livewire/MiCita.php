<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\AppointmentEventTypeEnum;
use App\Enums\AppointmentStatusEnum;
use App\Enums\AppointmentTypeEnum;
use App\Enums\DocumentTypeEnum;
use App\Jobs\FormSatAppointmentJob;
use App\Models\Appointment;
use App\Models\Document;
use App\Models\Soldado;
use App\Models\User;
use App\Notifications\SoldadoCitaUpdateNotification;
use App\Services\Document\DocumentAnalysisService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

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
    use WithFileUploads;

    /** Steps: none | status | reject | photo | verify | efirma | done */
    public string $step = 'status';

    /** Motivo que da el soldado cuando el SAT rechazó la cita. */
    public string $rejectionReason = '';

    public ?string $citaId = null;

    public string $empresa = '';

    /** The CSF photo the soldado uploads. */
    public $foto = null;

    public ?string $rfc = null;

    public bool $extrayendo = false;

    public ?string $extractError = null;

    public string $doneTitle = '';

    public string $doneBody = '';

    /** The e.firma cita created after confirming, pending the "will you go?" answer. */
    public ?string $fielCitaId = null;

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
            $this->step = 'photo';

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

    // --- Paso 2: foto de la CSF → extraer RFC ---

    public function updatedFoto(): void
    {
        $this->extractError = null;

        $this->validate([
            'foto' => 'required|file|max:12288|mimetypes:image/jpeg,image/png,image/webp,image/heic,application/pdf',
        ], [], ['foto' => 'archivo']);

        $this->extrayendo = true;

        try {
            $bytes = file_get_contents($this->foto->getRealPath());
            $mime = $this->foto->getMimeType() ?: 'image/jpeg';

            $fields = app(DocumentAnalysisService::class)
                ->extractFields(base64_encode($bytes), $mime, DocumentTypeEnum::CSF);

            $rfc = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) ($fields['rfc'] ?? '')));

            $this->rfc = $rfc !== '' ? $rfc : null;
            $this->step = 'verify';
        } catch (\Throwable $e) {
            Log::error('MiCita: falló la extracción de la CSF', ['error' => $e->getMessage()]);
            $this->extractError = 'No pudimos leer el documento. Toma la foto de nuevo, más clara y de frente.';
            $this->rfc = null;
            $this->step = 'verify';
        } finally {
            $this->extrayendo = false;
        }
    }

    public function retomar(): void
    {
        $this->foto = null;
        $this->rfc = null;
        $this->extractError = null;
        $this->step = 'photo';
    }

    // --- Paso 3: confirmar RFC → guardar todo ---

    public function confirmar(): void
    {
        $this->validate([
            'rfc' => 'required|string|min:12|max:13',
            'foto' => 'required|file',
        ], [
            'rfc.required' => 'Escribe el RFC.',
            'rfc.min' => 'El RFC de una empresa tiene 12 caracteres.',
        ]);

        $cita = $this->cita();

        if ($cita === null || $cita->registration === null) {
            return;
        }

        $rfc = strtoupper(trim($this->rfc));
        $registration = $cita->registration;

        // 1) Guardar la foto de la CSF como documento del expediente.
        $path = $this->foto->store("documents/{$registration->id}/csf", 's3');

        Document::create([
            'registration_id' => $registration->id,
            'type' => DocumentTypeEnum::CSF,
            'name' => 'CSF '.$this->empresa.'.'.$this->foto->getClientOriginalExtension(),
            'storage_path' => $path,
            'stage' => $registration->getRawOriginal('stage'),
            'verified_at' => now(),
        ]);
        // El DocumentObserver, al crearse la CSF, la nombra, extrae RFC + domicilio fiscal
        // (ExtractCsfDataJob) y la manda a China — el MISMO camino para cualquier subida.

        // 2) Guardar el RFC de la empresa (con él se forma la e.firma).
        $registration->update(['rfc' => $rfc]);

        // 3) Marcar la cita de RFC como asistida.
        $cita->update(['status' => AppointmentStatusEnum::ATTENDED]);
        $cita->recordEvent(AppointmentEventTypeEnum::ATTENDED, "El soldado completó la cita y subió la CSF. RFC: {$rfc}.", ['rfc' => $rfc], 'soldado');

        // 4) Preparar la cita de e.firma (por formar) con el mismo soldado, si no existe.
        $fiel = $registration->appointments()->where('type', AppointmentTypeEnum::FIEL->value)
            ->whereNotIn('status', [AppointmentStatusEnum::CANCELLED->value, AppointmentStatusEnum::REJECTED->value])
            ->first();

        if ($fiel === null) {
            $fiel = $registration->appointments()->create([
                'type' => AppointmentTypeEnum::FIEL->value,
                'status' => AppointmentStatusEnum::PENDING_FORMING->value,
                'soldado_id' => $cita->soldado_id,
            ]);
        }

        $this->fielCitaId = $fiel->id;

        $this->notifyAdmins("El soldado completó la cita de RFC de {$this->empresa} y subió la CSF. RFC capturado: {$rfc}.");

        $this->step = 'efirma';
    }

    // --- Paso 4: ¿vas a la de e.firma? → formar sola ---

    public function efirma(bool $va): void
    {
        $cita = $this->cita();

        if ($va && $this->fielCitaId !== null && $cita !== null) {
            // Asegura que el soldado quede ligado como representante legal (para el dropdown/forma).
            if ($cita->soldado_id !== null && $cita->registration !== null) {
                $ya = $cita->registration->soldados()->where('soldados.id', $cita->soldado_id)->exists();
                if (! $ya) {
                    $cita->registration->soldados()->attach($cita->soldado_id, ['role' => 'legal_representative']);
                }
            }

            FormSatAppointmentJob::dispatch($this->fielCitaId);

            $this->doneTitle = '¡Listo! 🚀';
            $this->doneBody = 'Ya se está formando tu cita de e.firma de '.$this->empresa.'. Te avisamos por correo cuando el SAT le dé fecha.';
        } else {
            $this->doneTitle = '¡Listo! ✅';
            $this->doneBody = 'Guardamos todo. Gracias.';
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
