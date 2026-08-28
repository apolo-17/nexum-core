<?php

namespace App\Jobs;

use App\Models\Document;
use App\Notifications\ChinaDeliveryNotification;
use App\Services\Singapur\RelayDocumentAlertService;
use App\Services\Singapur\RelayFileService;
use App\Services\Singapur\RelayMessageAi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;

/**
 * Notifies the China/Singapur relay that a deliverable document is ready to pull.
 *
 * Dispatched by DocumentObserver when a deliverable document gets its file. Runs
 * on the queue so it never blocks the request that stored the document, and retries
 * with backoff on transient relay failures.
 *
 * A size-safe copy is prepared first (oversized scanned PDFs are compressed). If the
 * served file still exceeds what China can actually ingest, the job fails fast with a
 * human-readable reason instead of hanging ~130 s on China's timeout across five retries.
 *
 * The alert's event_id is DETERMINISTIC (derived from the document and the exact file
 * served), so retries and manual resends reuse the same id and China deduplicates them —
 * a document is never uploaded to Drive twice for the same content.
 */
class NotifyRelayDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted before failing.
     */
    public int $tries = 5;

    /**
     * ISO 8601 timestamp captured when the alert was first dispatched.
     */
    private readonly string $occurredAt;

    /**
     * @param  string  $documentId  ULID of the deliverable document that is ready.
     */
    public function __construct(private readonly string $documentId)
    {
        $this->occurredAt = Carbon::now()->toIso8601String();
    }

    /**
     * Seconds to wait between retries (exponential-ish backoff).
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    /**
     * Send the alert to the relay.
     *
     * Silently returns when the document has vanished or lost its file since the
     * job was queued, so a deleted/replaced document does not fail the queue.
     *
     * @param  RelayDocumentAlertService  $service  The alert sender.
     * @param  RelayFileService  $files  Prepares a size-safe copy for the relay to pull.
     */
    public function handle(RelayDocumentAlertService $service, RelayFileService $files): void
    {
        $document = Document::with('registration')->find($this->documentId);

        if ($document === null || ! $service->shouldAlert($document)) {
            return;
        }

        // Make sure the file the relay will pull is as small as possible (compress oversized
        // scanned PDFs) BEFORE announcing it — China pulls synchronously during the alert.
        $files->prepare($document);
        $document->refresh();

        // China's Drive pipeline chokes on files bigger than a few MB. If, even after
        // compression, the served file is over the ceiling, do not attempt the send (it would
        // hang ~130 s and 502). Mark it failed with a clear reason so the panel can show it.
        $servedBytes = $this->servedBytes($document);
        $ceiling = (int) config('services.singapur.china_max_bytes');

        if ($ceiling > 0 && $servedBytes > $ceiling) {
            $mb = round($servedBytes / 1048576, 1);
            $limitMb = round($ceiling / 1048576, 1);
            $this->markFailed(
                $document,
                "El archivo pesa {$mb} MB y excede el límite que China puede recibir (~{$limitMb} MB). ".
                'China debe ampliar su tubería de subida para aceptarlo.',
            );

            return;
        }

        $service->send($document, $this->deterministicEventId($document), $this->occurredAt);
    }

    /**
     * After all retries are exhausted, record the failure (so the panel shows a reason and a
     * resend button) and alert the super admins with an AI-composed, human-readable explanation.
     */
    public function failed(\Throwable $exception): void
    {
        $document = Document::with('registration')->find($this->documentId);

        if ($document === null) {
            return;
        }

        $message = app(RelayMessageAi::class)
            ->explainFailure($document, $exception->getMessage());

        $this->markFailed($document, $message, notify: true);
    }

    /**
     * Stamp the document as failed with a human-readable reason. Does not touch storage_path,
     * so a corrected re-upload still re-triggers a fresh send.
     */
    private function markFailed(Document $document, string $reason, bool $notify = false): void
    {
        $document->forceFill([
            'relay_failed_at' => now(),
            'relay_last_error' => $reason,
        ])->saveQuietly();

        if ($notify) {
            app(RelayDocumentAlertService::class)->notifySuperAdmins(
                ChinaDeliveryNotification::for($document, 'failed', $reason),
            );
        }
    }

    /**
     * Size in bytes of the exact file the relay will pull (compressed derivative when present).
     */
    private function servedBytes(Document $document): int
    {
        $path = filled($document->relay_storage_path)
            ? (string) $document->relay_storage_path
            : (string) $document->storage_path;

        try {
            return (int) Storage::disk()->size($path);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Stable UUIDv5 for this alert: same document + same served file => same id, so retries
     * and manual resends are idempotent and China never stores a second copy for one content.
     */
    private function deterministicEventId(Document $document): string
    {
        $served = filled($document->relay_storage_path)
            ? (string) $document->relay_storage_path
            : (string) $document->storage_path;

        return (string) Uuid::uuid5(Uuid::NAMESPACE_URL, "relay-doc:{$document->id}:{$served}");
    }
}
