<?php

namespace App\Notifications;

use App\Filament\Resources\RegistrationResource;
use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Bell alert to super admins about the delivery of a deliverable document to China.
 *
 * outcome:
 *   - 'delivered': China confirmed it (shows the Drive link).
 *   - 'failed':    the send failed after retries (message composed by AI).
 *   - 'rejected':  China rejected the document (message = the translated reason).
 */
class ChinaDeliveryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $registrationId,
        private readonly string $companyName,
        private readonly string $documentLabel,
        private readonly string $outcome,
        private readonly string $message,
        private readonly ?string $driveUrl = null,
    ) {}

    /**
     * Build the notification from a document + outcome (resolves labels once, at dispatch).
     */
    public static function for(Document $document, string $outcome, string $message, ?string $driveUrl = null): self
    {
        $type = $document->type instanceof \BackedEnum ? $document->type : null;

        return new self(
            registrationId: (string) $document->registration_id,
            companyName: $document->registration?->primaryLegalName?->name
                ?? $document->registration?->singapur_client_code
                ?? 'la empresa',
            documentLabel: $type?->label() ?? (string) $document->type,
            outcome: $outcome,
            message: $message,
            driveUrl: $driveUrl,
        );
    }

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(mixed $notifiable): array
    {
        [$title] = match ($this->outcome) {
            'delivered' => ['✅ Documento enviado a China'],
            'failed' => ['❌ Falló el envío a China'],
            'rejected' => ['⛔ China rechazó un documento'],
            default => ['ℹ️ Entrega a China'],
        };

        $body = "{$this->documentLabel} de {$this->companyName}: {$this->message}";

        return [
            'title' => $title,
            'body' => $body,
            'outcome' => $this->outcome,
            'drive_url' => $this->driveUrl,
            'url' => RegistrationResource::getUrl('view', ['record' => $this->registrationId]),
        ];
    }
}
