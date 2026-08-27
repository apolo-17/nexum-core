<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\Singapur\RelayDocumentAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Notifies the China/Singapur relay that a deliverable document is ready to pull.
 *
 * Dispatched by DocumentObserver when a deliverable document gets its file. Runs
 * on the queue so it never blocks the request that stored the document, and retries
 * with backoff on transient relay failures.
 *
 * The event_id and occurred_at are captured once at dispatch time and serialized,
 * so every retry sends the same values and the relay deduplicates the alert.
 */
class NotifyRelayDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted before failing.
     */
    public int $tries = 5;

    /**
     * Stable idempotency key for this alert, reused across retries.
     */
    private readonly string $eventId;

    /**
     * ISO 8601 timestamp captured when the alert was first dispatched.
     */
    private readonly string $occurredAt;

    /**
     * @param  string  $documentId  ULID of the deliverable document that is ready.
     */
    public function __construct(private readonly string $documentId)
    {
        $this->eventId = (string) Str::uuid();
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
     */
    public function handle(RelayDocumentAlertService $service): void
    {
        $document = Document::with('registration')->find($this->documentId);

        if ($document === null || ! $service->shouldAlert($document)) {
            return;
        }

        $service->send($document, $this->eventId, $this->occurredAt);
    }

    /**
     * After all retries are exhausted, alert the super admins with an AI-composed,
     * human-readable explanation of why the delivery to China failed.
     */
    public function failed(\Throwable $exception): void
    {
        $document = Document::with('registration')->find($this->documentId);

        if ($document === null) {
            return;
        }

        $message = app(\App\Services\Singapur\RelayMessageAi::class)
            ->explainFailure($document, $exception->getMessage());

        app(RelayDocumentAlertService::class)->notifySuperAdmins(
            \App\Notifications\ChinaDeliveryNotification::for($document, 'failed', $message),
        );
    }
}
