<?php

namespace App\Notifications;

use App\Filament\Resources\RegistrationResource;
use App\Models\Registration;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notifies admin users when a new incorporation expedient arrives from the Singapur relay.
 *
 * Delivered exclusively via the database channel so Filament can display it
 * in the top-bar bell-icon notification feed. No email or broadcast is sent.
 */
class NewExpedienteReceived extends Notification
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
     * @param  mixed  $notifiable
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    /**
     * Build the database notification payload stored in the notifications table.
     *
     * The `url` key is used by Filament's notification feed to render an
     * action button that links directly to the expedient view page.
     *
     * @param  mixed  $notifiable
     * @return array<string, mixed>
     */
    public function toDatabase(mixed $notifiable): array
    {
        $companyName = $this->registration
            ->legalNames()
            ->where('priority', 1)
            ->value('name') ?? 'Sin nombre';

        return [
            'title' => 'Nuevo expediente recibido',
            'body'  => "Código {$this->registration->singapur_client_code} · {$companyName}",
            'url'   => RegistrationResource::getUrl('view', ['record' => $this->registration]),
        ];
    }
}
