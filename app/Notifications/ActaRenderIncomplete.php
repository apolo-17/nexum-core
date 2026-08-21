<?php

namespace App\Notifications;

use App\Filament\Resources\RegistrationResource;
use App\Models\Registration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifies the team that a company's acta render could NOT be built because data is missing,
 * listing exactly what to add (a socio without a passport, a missing folio, too few
 * apoderados, etc.). Once the team fills the gaps they re-run the render.
 */
class ActaRenderIncomplete extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $issues  What is missing to build the acta.
     */
    public function __construct(
        private readonly Registration $registration,
        private readonly array $issues,
    ) {}

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'title' => '⚠️ No se pudo completar el acta',
            'body' => "Acta de {$this->companyName()}: falta ".implode(' ', $this->issues),
            'url' => RegistrationResource::getUrl('view', ['record' => $this->registration]),
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("No se pudo completar el acta — {$this->companyName()}")
            ->line("El acta de **{$this->companyName()}** (código {$this->registration->singapur_client_code}) no se pudo completar. Falta:");

        foreach ($this->issues as $issue) {
            $mail->line("• {$issue}");
        }

        return $mail
            ->line('Corrige lo anterior en el expediente y vuelve a generar el render.')
            ->action('Ver expediente', RegistrationResource::getUrl('view', ['record' => $this->registration]));
    }

    private function companyName(): string
    {
        return $this->registration->primaryLegalName?->name
            ?? $this->registration->singapur_folder_name
            ?? $this->registration->singapur_client_code
            ?? 'la empresa';
    }
}
