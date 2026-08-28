<?php

namespace App\Observers;

use App\Jobs\NotifyRelayDocumentJob;
use App\Models\Document;
use App\Services\Singapur\RelayDocumentAlertService;

/**
 * Fires a relay alert when a deliverable document becomes available.
 *
 * Watches document writes and queues NotifyRelayDocumentJob the moment a
 * deliverable document (signed acta, CSF, e.firma, ...) first gets its stored
 * file, or when that file is replaced by a newer version. Inbound KYC documents
 * and drafts are ignored — RelayDocumentAlertService::shouldAlert gates the type.
 */
class DocumentObserver
{
    /**
     * @param  RelayDocumentAlertService  $alerts  Used to gate deliverable types.
     */
    public function __construct(private readonly RelayDocumentAlertService $alerts) {}

    /**
     * Handle the Document "saved" event (covers both create and update).
     *
     * Only queues an alert when the relay URL is configured, the document is a
     * deliverable with a file, and that file is newly attached or changed — so a
     * plain metadata edit never re-alerts.
     *
     * @param  Document  $document  The document that was saved.
     */
    public function saved(Document $document): void
    {
        if (blank(config('services.singapur.document_alert_url'))) {
            return;
        }

        if (! $this->alerts->isDeliverableType($document)) {
            return;
        }

        // Only a new file or a replacement triggers a (re)send — a plain metadata edit never does.
        if (! $document->wasRecentlyCreated && ! $document->wasChanged('storage_path')) {
            return;
        }

        // A new or replaced file supersedes any prior delivery/rejection, so it becomes eligible
        // to send again (this is how a corrected document gets re-sent after being marked wrong).
        // The stale compressed derivative is dropped too, so a fresh one is built from the new file.
        if ($document->relay_delivered_at !== null || $document->relay_rejected_at !== null || filled($document->relay_storage_path)) {
            $document->forceFill([
                'relay_delivered_at' => null,
                'relay_drive_url' => null,
                'relay_rejected_at' => null,
                'relay_rejection_reason' => null,
                'relay_storage_path' => null,
            ])->saveQuietly();
        }

        if (! $this->alerts->shouldAlert($document)) {
            return;
        }

        NotifyRelayDocumentJob::dispatch($document->id);
    }
}
