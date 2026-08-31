<?php

namespace App\Observers;

use App\Enums\DocumentTypeEnum;
use App\Jobs\ExtractActaPartiesJob;
use App\Jobs\ExtractCsfDataJob;
use App\Jobs\NotifyRelayDocumentJob;
use App\Models\Document;
use App\Services\Singapur\RelayDocumentAlertService;

/**
 * Single place that reacts to a document being uploaded, so every upload path (admin, soldado
 * mobile/resource, API, relay) behaves identically — no scattered, divergent handling:
 *
 *   - CSF          → normalizes its name and extracts RFC + fiscal address into the expediente.
 *   - Acta prot.   → extracts the fiscal attorneys (soldados) so the company can get its citas.
 *   - Deliverables → announces the document to China (relay pull model).
 *
 * All AI-backed steps are gated on the Anthropic key and only run for a NEW or replaced file.
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
     * @param  Document  $document  The document that was saved.
     */
    public function saved(Document $document): void
    {
        // Only a new file or a replacement triggers processing — a plain metadata edit never does.
        $fileChanged = $document->wasRecentlyCreated || $document->wasChanged('storage_path');

        $this->maybeProcessCsf($document, $fileChanged);
        $this->maybeExtractActaParties($document, $fileChanged);
        $this->maybeSendToChina($document, $fileChanged);
    }

    /**
     * A CSF, no matter how it was uploaded, gets a consistent name and its data extracted:
     * the RFC and the fiscal address are read with AI and written to the expediente (this is
     * why "already have a comprobante/CSF" should mean "already have the fiscal address").
     */
    private function maybeProcessCsf(Document $document, bool $fileChanged): void
    {
        if (! ($document->type instanceof DocumentTypeEnum) || $document->type !== DocumentTypeEnum::CSF) {
            return;
        }

        if (blank($document->storage_path) || ! $fileChanged) {
            return;
        }

        $this->normalizeCsfName($document);

        // Extraer RFC + domicilio fiscal e inyectarlos al expediente (idempotente; el RFC solo se
        // escribe si falta). Requiere la llave de IA.
        if (filled(config('services.anthropic.api_key'))) {
            ExtractCsfDataJob::dispatch($document->id)->afterCommit();
        }
    }

    /**
     * Give a CSF a recognizable name ("CSF — EMPRESA.ext") when it was stored with a cryptic
     * one (an ULID/hash from an automated path), so it is findable in the documents list.
     */
    private function normalizeCsfName(Document $document): void
    {
        $name = (string) $document->name;

        // Ya tiene un nombre claro (menciona "CSF"): no lo tocamos.
        if (stripos($name, 'csf') !== false) {
            return;
        }

        $empresa = $document->registration?->primaryLegalName?->name
            ?? (preg_replace('/^[0-9]+_/', '', (string) $document->registration?->singapur_folder_name) ?: null)
            ?? 'EMPRESA';

        $ext = strtolower(pathinfo((string) $document->storage_path, PATHINFO_EXTENSION)) ?: 'pdf';

        $document->forceFill(['name' => "CSF — {$empresa}.{$ext}"])->saveQuietly();
    }

    /**
     * When a freshly uploaded (or replaced) acta protocolizada lands on an expediente that has no
     * legal representatives yet, read it with AI to recover its fiscal attorneys — so a company that
     * only has its acta gets its soldados linked automatically, ready for SAT appointments.
     */
    private function maybeExtractActaParties(Document $document, bool $fileChanged): void
    {
        if (blank(config('services.anthropic.api_key'))) {
            return;
        }

        if (! ($document->type instanceof DocumentTypeEnum) || $document->type !== DocumentTypeEnum::ACTA_PROTOCOLIZADA) {
            return;
        }

        if (blank($document->storage_path) || ! $fileChanged) {
            return;
        }

        $registration = $document->registration;
        if ($registration === null || $registration->legalRepresentatives()->exists()) {
            return; // ya tiene apoderados ligados: no re-extraer automáticamente
        }

        ExtractActaPartiesJob::dispatch($registration->id);
    }

    /**
     * Announce a deliverable document to China the moment it gets (or replaces) its file.
     */
    private function maybeSendToChina(Document $document, bool $fileChanged): void
    {
        if (blank(config('services.singapur.document_alert_url'))) {
            return;
        }

        if (! $this->alerts->isDeliverableType($document) || ! $fileChanged) {
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

        // Marca "enviando" desde ya, para que el panel muestre el estado en vivo (no "falta enviar")
        // en cuanto se guarda el archivo, y se auto-refresque hasta que el envío se resuelva.
        $document->forceFill(['relay_sending_at' => now()])->saveQuietly();

        NotifyRelayDocumentJob::dispatch($document->id);
    }
}
