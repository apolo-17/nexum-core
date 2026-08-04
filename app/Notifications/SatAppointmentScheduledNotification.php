<?php

namespace App\Notifications;

use App\Enums\DocumentTypeEnum;
use App\Filament\Resources\MisCitasResource;
use App\Models\Appointment;
use App\Models\SatModule;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifies a soldado that one of their SAT appointments (RFC or FIEL) was scheduled.
 *
 * Includes the SAT office address, an "add to calendar" button (Google Calendar link +
 * a universal .ics attachment that Apple/Outlook open), and the checklist of documents
 * the soldado must bring. Sent when the bot reports a "scheduled" outcome.
 */
class SatAppointmentScheduledNotification extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly Appointment $appointment,
    ) {}

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SatAppointmentScheduledNotification: no se pudo avisar al soldado tras los reintentos.', [
            'appointment_id' => $this->appointment->id,
            'soldado_id' => $this->appointment->soldado_id,
            'error' => $exception->getMessage(),
        ]);
    }

    public function via(mixed $notifiable): array
    {
        return $notifiable instanceof User ? ['database', 'mail'] : ['mail'];
    }

    /**
     * Build the branded appointment email with address, calendar and document checklist.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $type = $this->appointment->type->label();
        $company = $this->appointment->registration?->primaryLegalName?->name
            ?? $this->appointment->registration?->singapur_folder_name
            ?? 'la empresa';
        $start = $this->appointment->scheduled_at;
        $when = $start?->format('d/m/Y H:i') ?? 'por confirmar';

        // Resolve the SAT office and its physical address (stored in sat_modules).
        $office = (string) ($this->appointment->office ?? '');
        $address = $this->officeAddress($office);
        $location = $address ?: $office;

        $mail = (new MailMessage)
            ->subject("Tu {$type} del SAT — {$when}")
            ->greeting('Hola '.($this->appointment->soldado?->name ?? ''))
            ->line("Se agendó tu **{$type}** ante el SAT para **{$company}**.")
            ->line("**Fecha y hora:** {$when}");

        if ($office !== '') {
            $mail->line("**Sede:** {$office}");
        }
        if ($address !== null) {
            $mail->line("**Dirección:** {$address}");
        }

        // "Agregar a tu calendario" — button opens Google Calendar; the .ics attachment
        // covers Apple Calendar / Outlook (they open .ics natively). No need to detect
        // which calendar: both options travel in the same email.
        if ($start !== null) {
            $mail->action('📅 Agregar a tu calendario', $this->googleCalendarUrl($start, $type, $company, $location));
            $mail->attachData(
                $this->icsContent($start, $type, $company, $location),
                'cita-sat.ics',
                ['mime' => 'text/calendar; charset=utf-8'],
            );
        }

        // Descargas: acuse de la cita y comprobante de domicilio (desde el sistema).
        $acuseUrl = $this->tempUrl($this->appointment->acknowledgment_path);
        $comprobanteUrl = $this->comprobanteUrl();

        // Checklist — feedback provided by the team.
        $mail->line('---')
            ->line('Necesitarás los siguientes documentos (descárgalos del sistema):')
            ->line($acuseUrl !== null
                ? "1) Acuse de la cita del SAT — [descargar aquí]({$acuseUrl})"
                : '1) Acuse de la cita del SAT (te lo compartiremos)')
            ->line($comprobanteUrl !== null
                ? "2) Comprobante de domicilio — [descargar aquí]({$comprobanteUrl})"
                : '2) Comprobante de domicilio (te lo compartiremos)')
            ->line('3) Tu INE o pasaporte')
            ->line('4) Recoge el Acta Constitutiva correspondiente')
            ->line('5) USB para la FIEL (opcional para el RFC)')
            ->line('**Preséntate 10 minutos antes** con:')
            ->line('1) Acuse de la cita del SAT')
            ->line('2) El Acta Constitutiva (recoger en oficinas)');

        return $mail->salutation('Backend Bridge');
    }

    public function toDatabase(mixed $notifiable): array
    {
        $type = $this->appointment->type->label();
        $when = $this->appointment->scheduled_at?->format('d/m/Y H:i') ?? 'por confirmar';

        return [
            'title' => "{$type} agendada",
            'body' => "Tu {$type} del SAT quedó agendada para el {$when}.",
            'url' => MisCitasResource::getUrl('index'),
        ];
    }

    /**
     * Temporary signed download URL for a stored file (14 days), so an email-only soldado
     * can download without logging in. Null when there is no file or the disk cannot sign.
     */
    private function tempUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        try {
            $disk = Storage::disk(config('filesystems.default'));

            return $disk->exists($path)
                ? $disk->temporaryUrl($path, now()->addDays(14))
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Download URL for the expediente's Mexican proof-of-address document, if uploaded.
     */
    private function comprobanteUrl(): ?string
    {
        $doc = $this->appointment->registration?->documents()
            ->where('type', DocumentTypeEnum::PROOF_OF_ADDRESS_MX->value)
            ->whereNotNull('storage_path')
            ->latest()
            ->first();

        return $this->tempUrl($doc?->storage_path);
    }

    /**
     * Resolve the office's physical address from sat_modules (fuzzy match by name).
     */
    private function officeAddress(string $office): ?string
    {
        if ($office === '') {
            return null;
        }

        $module = SatModule::query()
            ->whereNotNull('address')
            ->where('name', 'like', "%{$office}%")
            ->first();

        return $module?->address;
    }

    /**
     * Build a Google Calendar "add event" URL (1-hour block, Mexico City timezone).
     */
    private function googleCalendarUrl(Carbon $start, string $type, string $company, string $location): string
    {
        $fmt = fn (Carbon $d): string => $d->format('Ymd\THis');
        $end = $start->copy()->addHour();

        return 'https://calendar.google.com/calendar/render?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => "{$type} SAT — {$company}",
            'dates' => $fmt($start).'/'.$fmt($end),
            'ctz' => 'America/Mexico_City',
            'location' => $location,
            'details' => "Cita del SAT para {$company}. Preséntate 10 minutos antes con tu identificación, el comprobante de la cita y el Acta Constitutiva.",
        ]);
    }

    /**
     * Build a universal .ics event (Apple Calendar / Outlook open it natively).
     */
    private function icsContent(Carbon $start, string $type, string $company, string $location): string
    {
        $fmt = fn (Carbon $d): string => $d->format('Ymd\THis');
        $end = $start->copy()->addHour();
        $uid = $this->appointment->id.'@nexumcore.app';
        $esc = fn (string $s): string => addcslashes($s, ",;\\");

        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Nexum//SAT//ES',
            'BEGIN:VEVENT',
            "UID:{$uid}",
            'DTSTAMP:'.now()->format('Ymd\THis\Z'),
            'DTSTART;TZID=America/Mexico_City:'.$fmt($start),
            'DTEND;TZID=America/Mexico_City:'.$fmt($end),
            'SUMMARY:'.$esc("{$type} SAT — {$company}"),
            'LOCATION:'.$esc($location),
            'DESCRIPTION:'.$esc('Preséntate 10 minutos antes con tu identificación, el comprobante de la cita y el Acta Constitutiva.'),
            'END:VEVENT',
            'END:VCALENDAR',
        ]);
    }
}
