<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Jobs\NotifyRelayDocumentJob;
use App\Models\Document;
use App\Models\Registration;
use App\Notifications\ChinaDeliveryNotification;
use App\Services\Singapur\ChinaDeliverablesService;
use App\Services\Singapur\RelayDocumentAlertService;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Interactive "Entregables a China" panel: shows the five deliverables and their delivery
 * status, and hosts the Send / Resend / Mark-wrong controls right where the status lives.
 */
class ChinaDeliverablesPanel extends Component
{
    public string $registrationId;

    public function mount(Registration|string $registration): void
    {
        $this->registrationId = $registration instanceof Registration ? $registration->id : $registration;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getItemsProperty(): array
    {
        return app(ChinaDeliverablesService::class)->statusFor(
            Registration::findOrFail($this->registrationId),
        );
    }

    /** Deliverables we have but China has not confirmed yet (pending or rejected). */
    public function getPendingCountProperty(): int
    {
        return collect($this->items)->whereIn('state', ['pending', 'rejected'])->count();
    }

    /** Send (or resend) one deliverable to China. */
    public function send(string $type): void
    {
        $doc = $this->latestDoc($type);

        if ($doc === null) {
            return;
        }

        $this->dispatchDoc($doc);

        Notification::make()
            ->title('Enviando a China…')
            ->body('Te avisaremos el resultado en la campana.')
            ->info()
            ->send();
    }

    /** Send every pending/rejected deliverable at once. */
    public function sendAllPending(): void
    {
        $sent = 0;

        foreach ($this->items as $item) {
            if (! in_array($item['state'], ['pending', 'rejected'], true)) {
                continue;
            }

            $doc = $this->latestDoc($item['type']);
            if ($doc !== null) {
                $this->dispatchDoc($doc);
                $sent++;
            }
        }

        Notification::make()
            ->title('Enviando a China…')
            ->body($sent.' documento(s) en camino. Te avisaremos por cada uno.')
            ->info()
            ->send();
    }

    /** Flag a deliverable as wrong (operator side), with a reason. */
    public function markWrong(string $type, ?string $reason): void
    {
        $reason = trim((string) $reason);

        if ($reason === '') {
            return;
        }

        $doc = $this->latestDoc($type);
        if ($doc === null) {
            return;
        }

        $doc->forceFill([
            'relay_rejected_at' => now(),
            'relay_rejection_reason' => $reason,
        ])->save();

        app(RelayDocumentAlertService::class)->notifySuperAdmins(
            ChinaDeliveryNotification::for($doc, 'rejected', $reason),
        );

        Notification::make()
            ->title('Documento marcado como erróneo')
            ->body('Sube el correcto y vuelve a enviarlo.')
            ->warning()
            ->send();
    }

    private function latestDoc(string $type): ?Document
    {
        return Document::query()
            ->where('registration_id', $this->registrationId)
            ->where('type', $type)
            ->whereNotNull('storage_path')
            ->latest()
            ->first();
    }

    private function dispatchDoc(Document $doc): void
    {
        // Reinicia el estado de entrega para que sea elegible y se (re)envíe.
        $doc->forceFill([
            'relay_delivered_at' => null,
            'relay_drive_url' => null,
            'relay_rejected_at' => null,
            'relay_rejection_reason' => null,
        ])->saveQuietly();

        NotifyRelayDocumentJob::dispatch($doc->id);
    }

    public function render()
    {
        return view('livewire.china-deliverables-panel');
    }
}
