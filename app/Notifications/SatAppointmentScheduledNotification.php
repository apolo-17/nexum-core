<?php

namespace App\Notifications;

use App\Filament\Resources\MisCitasResource;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notifies a soldado that one of their SAT appointments (RFC or FIEL) was scheduled.
 *
 * Sent when the nexum-citas-sat bot reports a "scheduled" outcome. Delivered by email
 * (branded) and, when the soldado has a dashboard account, also as a bell notification.
 *
 * Queued (ShouldQueue) so a transient mail hiccup (Resend rate limit, timeout) is
 * retried automatically instead of being lost. After all retries fail, failed() records
 * exactly what went wrong. Needs a queue worker (Horizon) running.
 *
 * WhatsApp/SMS: add a channel here (e.g. 'whatsapp') once a provider is wired — the
 * soldado's phone is on the model.
 */
class SatAppointmentScheduledNotification extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Retry a failed delivery up to 3 times before giving up.
     */
    public int $tries = 3;

    /**
     * @param  Appointment  $appointment  The scheduled appointment.
     */
    public function __construct(
        private readonly Appointment $appointment,
    ) {}

    /**
     * Wait 10s, then 30s, then 60s between retries (transient mail errors clear fast).
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * Record the failure after all retries are exhausted, so it is never lost silently.
     *
     * @param  Throwable  $exception  Why the last attempt failed.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('SatAppointmentScheduledNotification: no se pudo avisar al soldado tras los reintentos.', [
            'appointment_id' => $this->appointment->id,
            'soldado_id' => $this->appointment->soldado_id,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Declare the delivery channels.
     *
     * Dashboard users also get a bell notification; on-demand (email-only) soldados
     * just get the mail.
     *
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return $notifiable instanceof User ? ['database', 'mail'] : ['mail'];
    }

    /**
     * Build the branded appointment email.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $type = $this->appointment->type->label();
        $company = $this->appointment->registration?->primaryLegalName?->name
            ?? $this->appointment->registration?->singapur_folder_name
            ?? 'la empresa';
        $when = $this->appointment->scheduled_at?->format('d/m/Y H:i') ?? 'por confirmar';

        $message = (new MailMessage)
            ->subject("Tu {$type} del SAT fue agendada")
            ->greeting('Hola '.($this->appointment->soldado?->name ?? ''))
            ->line("Se agendó tu **{$type}** ante el SAT para **{$company}**.")
            ->line("Fecha y hora: **{$when}**.");

        if (filled($this->appointment->office)) {
            $message->line("Sede: {$this->appointment->office}.");
        }

        return $message->line('Por favor preséntate puntualmente con tu identificación.');
    }

    /**
     * Build the database (bell) notification for the soldado's dashboard.
     *
     * @return array<string, mixed>
     */
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
}
