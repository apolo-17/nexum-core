<?php

namespace App\Notifications;

use App\Filament\Resources\RegistrationResource;
use App\Models\Registration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifies the configured recipients when a new incorporation expedient is
 * successfully created from the Singapur relay.
 *
 * Delivered via the database channel (Filament top-bar bell feed) and by email
 * (Resend). Recipients and the on/off toggle are managed in the "Notificaciones"
 * settings module — see EventNotifier and NotificationSetting.
 */
class NewExpedienteReceived extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Registration  $registration  The newly created or updated registration.
     */
    public function __construct(
        private readonly Registration $registration,
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
     * The `url` key is used by Filament's notification feed to render an
     * action button that links directly to the expedient view page.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'title' => 'Nuevo expediente recibido',
            'body' => "Código {$this->registration->singapur_client_code} · {$this->companyName()}",
            'url' => RegistrationResource::getUrl('view', ['record' => $this->registration]),
        ];
    }

    /**
     * Build the branded email sent to the configured recipients.
     *
     * @param  mixed  $notifiable  The recipient User model.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Nuevo expediente recibido — {$this->registration->singapur_client_code}")
            ->markdown('mail.expediente.received', [
                'clientCode' => $this->registration->singapur_client_code,
                'companyName' => $this->companyName(),
                'url' => RegistrationResource::getUrl('view', ['record' => $this->registration]),
            ]);
    }

    /**
     * Resolve the priority-1 denomination for display, with a safe fallback.
     */
    private function companyName(): string
    {
        return $this->registration
            ->legalNames()
            ->where('priority', 1)
            ->value('name') ?? 'Sin nombre';
    }
}
