<?php

namespace App\Notifications;

use App\Enums\NotificationEventEnum;
use App\Filament\Resources\DenominationResource;
use App\Filament\Resources\RegistrationResource;
use App\Models\LegalName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifies the configured recipients about a denomination lifecycle change driven
 * by the MUA bot callback: registered at the SE, approved, or failed to submit.
 *
 * Delivered via the database channel (Filament bell feed) and by branded email
 * (Resend). Recipients and the on/off toggle per event are managed in the
 * "Notificaciones" settings module — see EventNotifier and NotificationSetting.
 *
 * One class covers the three events; the title, body, subject and email template
 * are selected from the NotificationEventEnum passed in.
 */
class DenominationStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  LegalName  $legalName  The denomination whose status changed.
     * @param  NotificationEventEnum  $event  Which lifecycle event occurred.
     * @param  string|null  $reason  Optional failure reason (only for the failed event).
     */
    public function __construct(
        private readonly LegalName $legalName,
        private readonly NotificationEventEnum $event,
        private readonly ?string $reason = null,
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
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->url(),
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
            ->subject($this->title())
            ->markdown($this->template(), [
                'name' => $this->legalName->name,
                'expedient' => $this->expedientLabel(),
                'reason' => $this->reason,
                'url' => $this->url(),
            ]);
    }

    /**
     * Resolve the short title / subject for the event.
     */
    private function title(): string
    {
        return match ($this->event) {
            NotificationEventEnum::DENOMINATION_SUBMITTED => "Denominación registrada en la SE — \"{$this->legalName->name}\"",
            NotificationEventEnum::DENOMINATION_APPROVED => "Denominación aprobada por la SE — \"{$this->legalName->name}\"",
            NotificationEventEnum::DENOMINATION_FAILED => "Error al enviar denominación — \"{$this->legalName->name}\"",
            default => "Actualización de denominación — \"{$this->legalName->name}\"",
        };
    }

    /**
     * Resolve the bell-feed body line for the event.
     */
    private function body(): string
    {
        $where = $this->expedientLabel();

        return match ($this->event) {
            NotificationEventEnum::DENOMINATION_SUBMITTED => "{$where} · El bot registró la denominación en el portal de la SE.",
            NotificationEventEnum::DENOMINATION_APPROVED => "{$where} · La SE autorizó la denominación. Constancia recibida.",
            NotificationEventEnum::DENOMINATION_FAILED => "{$where} · No se pudo registrar en la SE. Regresó a la cola para reenviar.",
            default => $where,
        };
    }

    /**
     * Resolve the branded markdown email template for the event.
     */
    private function template(): string
    {
        return match ($this->event) {
            NotificationEventEnum::DENOMINATION_SUBMITTED => 'mail.denomination.submitted',
            NotificationEventEnum::DENOMINATION_APPROVED => 'mail.denomination.approved',
            NotificationEventEnum::DENOMINATION_FAILED => 'mail.denomination.failed',
            default => 'mail.denomination.submitted',
        };
    }

    /**
     * Human-readable label of where the denomination belongs — its expedient code
     * when attached to a registration, or a pool label otherwise.
     */
    private function expedientLabel(): string
    {
        $registration = $this->legalName->registration;

        return $registration !== null
            ? "Expediente {$registration->singapur_client_code}"
            : 'Denominación de pool';
    }

    /**
     * Deep-link to the most relevant dashboard page: the registration view when the
     * denomination belongs to an expedient, otherwise the denominations pool list.
     */
    private function url(): string
    {
        $registration = $this->legalName->registration;

        return $registration !== null
            ? RegistrationResource::getUrl('view', ['record' => $registration])
            : DenominationResource::getUrl();
    }
}
