<?php

namespace App\Notifications;

use App\Filament\Resources\RegistrationResource;
use App\Models\Registration;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notifies the assigned notario when the SE resolves a denomination request.
 *
 * Delivered via the database channel so Filament displays it in the top-bar
 * bell-icon notification feed. Covers both approved and rejected outcomes.
 */
class DenominationResolvedNotification extends Notification
{
    use Queueable;

    /**
     * @param  Registration  $registration  The registration the denomination belongs to.
     * @param  string        $name          The denomination name that was resolved.
     * @param  bool          $approved      True when the SE approved it, false when rejected.
     */
    public function __construct(
        private readonly Registration $registration,
        private readonly string $name,
        private readonly bool $approved,
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
     * The title and body vary depending on whether the SE approved or rejected
     * the denomination. The url links directly to the registration view page.
     *
     * @param  mixed  $notifiable
     * @return array<string, mixed>
     */
    public function toDatabase(mixed $notifiable): array
    {
        $title = $this->approved
            ? 'Denominacion aprobada por la SE'
            : 'Denominacion rechazada por la SE';

        $code = $this->registration->singapur_client_code;

        $body = $this->approved
            ? "Expediente {$code} · \"{$this->name}\" fue autorizada. Puedes avanzar el tramite."
            : "Expediente {$code} · \"{$this->name}\" fue rechazada. Revisa y propone otro nombre.";

        return [
            'title' => $title,
            'body'  => $body,
            'url'   => RegistrationResource::getUrl('view', ['record' => $this->registration]),
        ];
    }
}
