<?php

namespace App\Services\Singapur;

use App\Enums\DocumentTypeEnum;
use App\Models\Document;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sends a lightweight "company document ready" alert to the China/Singapur relay.
 *
 * This is the sender half of the pull model: instead of pushing heavy files,
 * Nexum tells the relay a deliverable document is available. The relay then pulls
 * it from R2 through CompanyDocumentRelayController (a short-lived pre-signed URL),
 * so the bytes never travel through Nexum's application server.
 *
 * The alert body reuses the relay's event envelope (schema_version/event_type/
 * event_id/occurred_at/registration_number/data.document_type) and is authenticated
 * with the same shared secret the webhook already uses (X-Nexum-Secret).
 */
class RelayDocumentAlertService
{
    /**
     * Envelope version the relay expects.
     */
    private const SCHEMA_VERSION = '1.0';

    /**
     * Event type for a document-available alert.
     */
    private const EVENT_TYPE = 'company.document.available';

    /**
     * Map the deliverable Nexum document types to the relay's own document slugs.
     *
     * Only final deliverables are listed — e.g. the signed acta, not the draft —
     * so the relay is never alerted about a premature or internal document.
     *
     * @var array<string, string>
     */
    private const DELIVERABLE_SLUGS = [
        // El "incorporation_deed" que China necesita es la ESCRITURA PROTOCOLIZADA (acta
        // notariada), no el borrador firmado (acta_signed) previo al notario.
        DocumentTypeEnum::ACTA_PROTOCOLIZADA->value => 'incorporation_deed',
        DocumentTypeEnum::RPP_REGISTRATION->value => 'rpc_registration_receipt',
        DocumentTypeEnum::PROOF_OF_ADDRESS_MX->value => 'company_proof_of_address',
        DocumentTypeEnum::CSF->value => 'tax_status_certificate',
        DocumentTypeEnum::EFIRMA->value => 'e_firma',
    ];

    /**
     * Return the relay document slug for a Nexum document type, or null when the
     * type is not a deliverable the relay cares about.
     *
     * @param  DocumentTypeEnum  $type  The Nexum document type.
     */
    public function slugFor(DocumentTypeEnum $type): ?string
    {
        return self::DELIVERABLE_SLUGS[$type->value] ?? null;
    }

    /**
     * Determine whether a document should trigger a relay alert.
     *
     * True only when the document is a deliverable type and actually has a stored
     * file to pull.
     *
     * @param  Document  $document  The document to evaluate.
     */
    public function shouldAlert(Document $document): bool
    {
        return $document->type instanceof DocumentTypeEnum
            && $this->slugFor($document->type) !== null
            && filled($document->storage_path);
    }

    /**
     * POST the "document ready" alert to the relay.
     *
     * The event_id and occurred_at are supplied by the caller (the job) so retries
     * reuse the same values and the relay can deduplicate. Throws on transport or
     * non-2xx responses so the queue retries with backoff.
     *
     * @param  Document  $document  The deliverable document that is ready.
     * @param  string  $eventId  Stable UUID for this alert (reused across retries).
     * @param  string  $occurredAt  ISO 8601 timestamp with timezone.
     *
     * @throws RuntimeException When the relay URL is not configured or the call fails.
     */
    public function send(Document $document, string $eventId, string $occurredAt): void
    {
        $url = (string) config('services.singapur.document_alert_url');
        $secret = (string) config('services.singapur.webhook_secret');

        if ($url === '' || $secret === '') {
            throw new RuntimeException('Singapur document alert URL or secret is not configured.');
        }

        $slug = $document->type instanceof DocumentTypeEnum ? $this->slugFor($document->type) : null;

        if ($slug === null) {
            throw new RuntimeException("Document {$document->id} is not a deliverable relay type.");
        }

        $registrationNumber = (string) ($document->registration?->singapur_client_code ?? '');

        $response = Http::connectTimeout(10)
            ->timeout(30)
            ->withHeaders(['X-Nexum-Secret' => $secret])
            ->acceptJson()
            ->post($url, [
                'schema_version' => self::SCHEMA_VERSION,
                'event_type' => self::EVENT_TYPE,
                'event_id' => $eventId,
                'occurred_at' => $occurredAt,
                'registration_number' => $registrationNumber,
                'data' => [
                    'document_type' => $slug,
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Relay document alert failed (HTTP {$response->status()}): ".substr($response->body(), 0, 300)
            );
        }

        Log::info('RelayDocumentAlertService: alert sent', [
            'document_id' => $document->id,
            'document_type' => $slug,
            'registration_number' => $registrationNumber,
            'event_id' => $eventId,
        ]);
    }
}
