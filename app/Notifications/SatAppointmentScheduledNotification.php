<?php

namespace App\Notifications;

use App\Filament\Resources\MisCitasResource;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifies a soldado that one of their SAT appointments (RFC or FIEL) was scheduled.
 *
 * Sent when the nexum-citas-sat bot reports a "scheduled" outcome. Delivered by email
 * (branded) and, when the soldado has a dashboard account, also as a bell notification.
 *
 * WhatsApp/SMS: add a channel here (e.g. 'whatsapp') once a provider is wired — the
 * soldado's phone is on the model. Delivered synchronously so it arrives even without
 * a queue worker.
 */
class SatAppointmentScheduledNotification extends Notification
{
    use Queueable;

    /**
     * @param  Appointment  $appointment  The scheduled appointment.
     */
    public function __construct(
        private readonly Appointment $appointment,
    ) {}

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
