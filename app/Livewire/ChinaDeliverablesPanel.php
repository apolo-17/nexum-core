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

    public function mount(Registration|string $registration): void
    {
        $this->registrationId = $registration instanceof Registration ? $registration->id : $registration;
    }

    /**
     * Per-deliverable rows for the view. The "sending" state is DB-driven (relay_sending_at),
     * so a document that starts sending on upload — not only from a click here — shows progress.
     *
     * @return list<array<string, mixed>>
     */
    public function getItemsProperty(): array
    {
        return app(ChinaDeliverablesService::class)->statusFor(
            Registration::findOrFail($this->registrationId),
        );
    }

    /**
     * Auto-refresh cadence so the panel updates itself without a full page reload:
     *   - fast while a send is in flight (catch the flip to ✅/⚠️ quickly),
     *   - slow while anything is still not delivered (catch a just-uploaded document),
     *   - off once China has everything (nothing left to watch).
     */
    public function getPollIntervalProperty(): ?string
    {
        $states = collect($this->items)->pluck('state');

        if ($states->contains('sending')) {
            return '2s';
        }

        // Silent background refresh (no spinner) so a just-uploaded document shows up on its own;
        // stops entirely once China has everything, so a settled expediente does no polling at all.
        return $states->contains(fn (string $s): bool => $s !== 'delivered') ? '10s' : null;
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
        // Reinicia el estado de entrega para que sea elegible y se (re)envíe, y márcalo "enviando"
        // para que el panel muestre el estado en vivo hasta que el job lo resuelva.
        $doc->forceFill([
            'relay_delivered_at' => null,
            'relay_drive_url' => null,
            'relay_rejected_at' => null,
            'relay_rejection_reason' => null,
            'relay_failed_at' => null,
            'relay_last_error' => null,
            'relay_sending_at' => now(),
        ])->saveQuietly();

        NotifyRelayDocumentJob::dispatch($doc->id);
    }

    public function render()
    {
        return view('livewire.china-deliverables-panel');
    }
}
