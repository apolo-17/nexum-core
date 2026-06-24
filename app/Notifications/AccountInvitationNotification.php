<?php

namespace App\Notifications;

use Filament\Facades\Filament;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Welcome email sent when a super_admin invites a new team member.
 *
 * The body is branded Spanish copy and contains a button to Nexum's branded
 * Filament "set password" screen. The link is backed by a single-use password
 * reset token that expires in 60 minutes; the super_admin can re-issue it from
 * the user list ("Reenviar invitación") and the invitee can also use the
 * "¿Olvidaste tu contraseña?" link on the login page.
 *
 * Delivered synchronously (not queued) so the invitation is sent immediately
 * when the account is created, even in environments without a queue worker.
 */
class AccountInvitationNotification extends Notification
{
    use Queueable;

    /**
     * @param  string  $token  Single-use password reset token for the invitee.
     * @param  string|null  $roleLabel  Human-readable role label shown in the email.
     */
    public function __construct(
        private readonly string $token,
        private readonly ?string $roleLabel = null,
    ) {}

    /**
     * Declare the notification delivery channels.
     *
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the branded invitation email.
     *
     * @param  mixed  $notifiable  The invited User model.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $url = Filament::getResetPasswordUrl($this->token, $notifiable);
        $name = $notifiable->name ?? '';

        $message = (new MailMessage)
            ->subject('Bienvenido a Nexum Core — activa tu cuenta')
            ->greeting(trim('Hola '.$name))
            ->line('Se creó una cuenta para ti en Nexum Core, el panel de Backend Bridge Incorporation.');

        if ($this->roleLabel !== null) {
            $message->line("Tu rol asignado es: **{$this->roleLabel}**.");
        }

        return $message
            ->line('Para empezar, define tu contraseña con el siguiente botón:')
            ->action('Activar mi cuenta', $url)
            ->line('Este enlace caduca en 60 minutos. Si expira, solicita uno nuevo desde "¿Olvidaste tu contraseña?" en la pantalla de acceso.');
    }
}
