<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifies the TEAM (admins) about what the SAT bot did with an appointment.
 *
 * Covers the three moments they asked to be told about: the appointment was queued in
 * the virtual queue, the SAT assigned a date and time, or the bot hit an error. The
 * soldado gets their own, friendlier notification (SatAppointmentScheduledNotification)
 * only when there is an actual date to attend.
 *
 * Recipients are whoever is selected for the matching NotificationEventEnum in the
 * "Notificaciones" settings module — this class never decides who gets it.
 */
class SatAppointmentStatusNotification extends Notification
{
    use Queueable;

    /**
     * @param  Appointment  $appointment  The appointment the bot reported on.
     * @param  string  $outcome  One of: formed, scheduled, failed.
     * @param  string|null  $reason  Failure detail, when the outcome is a failure.
     */
    public function __construct(
        private readonly Appointment $appointment,
        private readonly string $outcome,
        private readonly ?string $reason = null,
    ) {}

    /**
     * Declare the delivery channels: dashboard bell + email.
     *
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Build the email for the team.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $company = $this->appointment->registration?->primaryLegalName?->name
            ?? $this->appointment->registration?->singapur_folder_name
            ?? 'Empresa sin nombre';
        $soldado = $this->appointment->soldado?->name ?? 'sin soldado';
        $type = $this->appointment->type->label();

        $mail = (new MailMessage)
            ->subject($this->subjectLine($company))
            ->greeting('Bot de citas del SAT')
            ->line("**Empresa:** {$company}")
            ->line("**Trámite:** {$type}")
            ->line("**Soldado:** {$soldado}");

        if (filled($this->appointment->office)) {
            $mail->line("**Sucursal:** {$this->appointment->office}");
        }

        return match ($this->outcome) {
            'formed' => $mail
                ->line('La cita quedó **formada en la fila virtual**. El SAT asignará fecha '
                    .'y hora más adelante; el bot la revisa periódicamente.'),
            'scheduled' => $mail
                ->line('**El SAT ya asignó fecha y hora.**')
                ->line('**Cita:** '.($this->appointment->scheduled_at?->format('d/m/Y H:i') ?? 'sin fecha'))
                ->line('Ya se le avisó al soldado.'),
            default => $mail
                ->line('**El bot no pudo completar el trámite.**')
                ->line('**Motivo:** '.($this->reason ?: 'sin detalle'))
                ->line('La cita se queda como está y se reintenta.'),
        };
    }

    /**
     * Build the subject line for each outcome.
     *
     * @param  string  $company  Company name shown in the subject.
     */
    private function subjectLine(string $company): string
    {
        return match ($this->outcome) {
            'formed' => "Cita SAT formada — {$company}",
            'scheduled' => "Cita SAT con fecha — {$company}",
            default => "Error con la cita SAT — {$company}",
        };
    }

    /**
     * Payload for the dashboard bell notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'appointment_id' => $this->appointment->id,
            'registration_id' => $this->appointment->registration_id,
            'outcome' => $this->outcome,
            'scheduled_at' => $this->appointment->scheduled_at?->toDateTimeString(),
            'office' => $this->appointment->office,
            'reason' => $this->reason,
        ];
    }
}
