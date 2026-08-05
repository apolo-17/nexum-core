<?php

namespace App\Notifications;

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
 * Notifies a soldado that one of their SAT appointments was cancelled by the team.
 *
 * The point is that the soldado does NOT show up to a cita that no longer exists. Sent
 * when someone cancels from the panel; only meaningful when the cita already had a date.
 */
class SatAppointmentCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  Appointment  $appointment  The cancelled appointment.
     * @param  string|null  $reason  Optional cancellation reason (not shown verbatim).
     */
    public function __construct(
        private readonly Appointment $appointment,
        private readonly ?string $reason = null,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SatAppointmentCancelledNotification: no se pudo avisar al soldado tras los reintentos.', [
            'appointment_id' => $this->appointment->id,
            'soldado_id' => $this->appointment->soldado_id,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return $notifiable instanceof User ? ['database', 'mail'] : ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $company = $this->appointment->registration?->primaryLegalName?->name
            ?? $this->appointment->registration?->singapur_folder_name
            ?? 'la empresa';
        $type = $this->appointment->type->label();
        $whenLine = $this->appointment->scheduled_at !== null
            ? 'Estaba programada para el '.$this->appointment->scheduled_at->format('d/m/Y \a \l\a\s H:i').'.'
            : null;

        $mail = (new MailMessage)
            ->subject('Tu cita del SAT fue cancelada')
            ->greeting('Hola'.($notifiable instanceof User && $notifiable->name ? ", {$notifiable->name}" : ''))
            ->line("Tu cita de **{$type}** para **{$company}** fue **cancelada**.");

        if ($whenLine !== null) {
            $mail->line($whenLine);
        }

        return $mail
            ->line('**No te presentes** a esta cita: ya no es válida.')
            ->line('Si hay que reagendar, el equipo te avisará con una nueva fecha. Gracias.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'appointment_id' => $this->appointment->id,
            'registration_id' => $this->appointment->registration_id,
            'type' => $this->appointment->type->value,
            'scheduled_at' => $this->appointment->scheduled_at?->toDateTimeString(),
            'reason' => $this->reason,
        ];
    }
}
