<?php

namespace App\Notifications;

use App\Models\WebhookEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifies the configured recipients when an incoming expedient from the Singapur
 * relay fails to process.
 *
 * Shares the EXPEDIENTE_RECEIVED event toggle with NewExpedienteReceived so a
 * super_admin opting into "Recepción de expedientes" is alerted to both
 * successful and failed deliveries. Delivered via the database bell feed and by
 * email (Resend) so a silent webhook failure does not go unnoticed.
 */
class ExpedienteReceptionFailed extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  WebhookEvent  $webhookEvent  The event that failed to process.
     * @param  string  $errorMessage  The exception message captured on failure.
     */
    public function __construct(
        private readonly WebhookEvent $webhookEvent,
        private readonly string $errorMessage,
    ) {}

    /**
     * Declare the notification delivery channels.
     *
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Build the database notification payload stored in the notifications table.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'title' => 'Error al recibir un expediente',
            'body' => "Evento {$this->webhookEvent->event_id} falló: {$this->errorMessage}",
            'url' => null,
        ];
    }

    /**
     * Build the branded failure-alert email.
     *
     * @param  mixed  $notifiable  The recipient User model.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject('Error al recibir un expediente de China')
            ->markdown('mail.expediente.failed', [
                'eventId' => $this->webhookEvent->event_id,
                'source' => $this->webhookEvent->source,
                'errorMessage' => $this->errorMessage,
            ]);
    }
}
