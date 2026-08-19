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

        if (! $this->alerts->shouldAlert($document)) {
            return;
        }

        if (! $document->wasRecentlyCreated && ! $document->wasChanged('storage_path')) {
            return;
        }

        NotifyRelayDocumentJob::dispatch($document->id);
    }
}
