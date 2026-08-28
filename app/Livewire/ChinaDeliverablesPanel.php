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
use Livewire\Component;

/**
 * Interactive "Entregables a China" panel: shows the five deliverables and their delivery
 * status, and hosts the Send / Resend / Mark-wrong controls right where the status lives.
 */
class ChinaDeliverablesPanel extends Component
{
    public string $registrationId;

    /**
     * Deliverable types the operator just fired that have not resolved yet, keyed by type.
     * Kept in component state so the row shows a persistent "Enviando a China…" spinner
     * across wire:poll refreshes until the send lands (delivered) or fails.
     *
     * @var array<string, bool>
     */
    public array $sending = [];

    public function mount(Registration|string $registration): void
    {
        $this->registrationId = $registration instanceof Registration ? $registration->id : $registration;
    }

    /**
     * Per-deliverable rows for the view, with the transient "sending" state layered on top of
     * the persisted delivery status so a just-clicked row shows progress until it resolves.
     *
     * @return list<array<string, mixed>>
     */
    public function getItemsProperty(): array
    {
        $items = app(ChinaDeliverablesService::class)->statusFor(
            Registration::findOrFail($this->registrationId),
        );

        foreach ($items as &$item) {
            $type = $item['type'];

            if (! ($this->sending[$type] ?? false)) {
                continue;
            }

            // Once the delivery resolved (delivered / rejected / failed), drop the spinner and
            // show the real outcome; otherwise keep showing "Enviando…".
            if (in_array($item['state'], ['delivered', 'rejected', 'failed'], true)) {
                unset($this->sending[$type]);
            } else {
                $item['state'] = 'sending';
            }
        }
        unset($item);

        return $items;
    }

    /** Deliverables we have but China has not confirmed yet (pending, rejected or failed). */
    public function getPendingCountProperty(): int
    {
        return collect($this->items)->whereIn('state', ['pending', 'rejected', 'failed'])->count();
    }

    /** Send (or resend) one deliverable to China. */
    public function send(string $type): void
    {
        $doc = $this->latestDoc($type);

        if ($doc === null) {
            return;
        }

        $this->dispatchDoc($doc);
        $this->sending[$type] = true;

        Notification::make()
            ->title('Enviando a China…')
            ->body('El estatus se actualizará solo aquí cuando termine.')
            ->info()
            ->send();
    }

    /** Send every pending/rejected/failed deliverable at once. */
    public function sendAllPending(): void
    {
        $sent = 0;

        foreach ($this->items as $item) {
            if (! in_array($item['state'], ['pending', 'rejected', 'failed'], true)) {
                continue;
            }

            $doc = $this->latestDoc($item['type']);
            if ($doc !== null) {
                $this->dispatchDoc($doc);
                $this->sending[$item['type']] = true;
                $sent++;
            }
        }

        Notification::make()
            ->title('Enviando a China…')
            ->body($sent.' documento(s) en camino. El estatus se actualizará solo aquí.')
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
            'relay_failed_at' => null,
            'relay_last_error' => null,
        ])->saveQuietly();

        NotifyRelayDocumentJob::dispatch($doc->id);
    }

    public function render()
    {
        return view('livewire.china-deliverables-panel');
    }
}
