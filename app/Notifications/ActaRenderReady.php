<?php

namespace App\Notifications;

use App\Filament\Resources\RegistrationResource;
use App\Models\Registration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifies the team that a company's acta render (ACTA_DRAFT) was built automatically and
 * is ready to review. Any non-blocking KYC warnings (e.g. a name to double-check against a
 * passport) are listed so the reviewer knows what to verify before finalizing.
 */
class ActaRenderReady extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $warnings  Non-blocking review notes (may be empty).
     */
    public function __construct(
        private readonly Registration $registration,
        private readonly array $warnings = [],
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
        $body = "Acta de {$this->companyName()} lista para revisar.";

        if ($this->warnings !== []) {
            $body .= ' Revisa: '.implode(' ', $this->warnings);
        }

        return [
            'title' => '📄 Acta lista para revisar',
            'body' => $body,
            'url' => RegistrationResource::getUrl('view', ['record' => $this->registration]),
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Acta lista para revisar — {$this->companyName()}")
            ->line("El acta de **{$this->companyName()}** (código {$this->registration->singapur_client_code}) se generó automáticamente y está lista para revisar.");

        if ($this->warnings !== []) {
            $mail->line('Antes de finalizar, revisa lo siguiente:');
            foreach ($this->warnings as $warning) {
                $mail->line("• {$warning}");
            }
        }

        return $mail->action('Ver expediente', RegistrationResource::getUrl('view', ['record' => $this->registration]));
    }

    private function companyName(): string
    {
        return $this->registration->primaryLegalName?->name
            ?? $this->registration->singapur_folder_name
            ?? $this->registration->singapur_client_code
            ?? 'la empresa';
    }
}
