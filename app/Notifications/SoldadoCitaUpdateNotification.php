<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the admin team that a soldado updated their own cita from the mobile flow
 * (marked the result and/or uploaded the CSF with the extracted RFC).
 */
class SoldadoCitaUpdateNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $empresa,
        private readonly string $body,
    ) {}

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Un soldado actualizó su cita — {$this->empresa}")
            ->line($this->body)
            ->line('Revisa el expediente para validar el RFC capturado.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'title' => "Soldado actualizó su cita — {$this->empresa}",
            'body' => $this->body,
        ];
    }
}
