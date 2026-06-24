<?php

namespace App\Jobs;

use App\Enums\DocumentTypeEnum;
use App\Models\Document;
use App\Models\Registration;
use App\Services\DocuSign\DocuSignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Process a DocuSign Connect webhook notification asynchronously.
 *
 * DocuSign sends status updates (envelope.sent, envelope.delivered, envelope.completed,
 * envelope.voided, recipient.completed, etc.) to our webhook endpoint. This job
 * handles the ones we care about:
 *
 *   - envelope.completed → download the signed PDF and store it as ACTA_SIGNED.
 *   - envelope.voided    → log and mark the sign_status as voided for review.
 *   - recipient.completed → update the per-signer status in the ACTA_FINAL template_data.
 *
 * The HMAC verification is done at the controller level before dispatching this job,
 * so payload authenticity is guaranteed at this point.
 *
 * DocuSign sends JSON Connect payloads when the Connect profile is set to JSON format.
 * Configure the DocuSign Connect URL as: POST /api/v3/webhook/docusign
 */
class ProcessDocuSignWebhookJob implements ShouldQueue
{
    use Queueable;

    /**
     * Maximum number of times the job may be attempted before failing.
     */
    public int $tries = 3;

    /**
     * Number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    /**
     * @param  array<string, mixed>  $payload  Decoded JSON body from DocuSign Connect.
     * @param  string  $rawBody  Raw request body (for logging; HMAC already verified upstream).
     */
    public function __construct(
        private readonly array $payload,
        private readonly string $rawBody,
    ) {}

    /**
     * Execute the job — route the event to the appropriate handler based on event type.
     *
     * @param  DocuSignService  $docuSignService  Injected DocuSign service.
     */
    public function handle(DocuSignService $docuSignService): void
    {
        $event = $this->payload['event'] ?? null;
        $envelopeId = $this->payload['data']['envelopeId'] ?? ($this->payload['envelopeId'] ?? null);

        Log::info('ProcessDocuSignWebhookJob: received event', [
            'event' => $event,
            'envelope_id' => $envelopeId,
        ]);

        if ($envelopeId === null) {
            Log::warning('ProcessDocuSignWebhookJob: no envelopeId in payload', ['payload' => $this->payload]);

            return;
        }

        match ($event) {
            'envelope-completed' => $this->handleEnvelopeCompleted($envelopeId, $docuSignService),
            'envelope-voided' => $this->handleEnvelopeVoided($envelopeId),
            'recipient-completed' => $this->handleRecipientCompleted($envelopeId),
            default => Log::debug('ProcessDocuSignWebhookJob: unhandled event type', ['event' => $event]),
        };
    }

    /**
     * Handle envelope.completed — all signers have signed.
     *
     * Downloads the signed PDF from DocuSign, stores it as ACTA_SIGNED in R2,
     * and updates the ACTA_FINAL sign_status to "completed".
     *
     * @param  string  $envelopeId  The completed envelope.
     * @param  DocuSignService  $docuSignService  Service used to download the signed PDF.
     */
    private function handleEnvelopeCompleted(string $envelopeId, DocuSignService $docuSignService): void
    {
        $registration = $this->findRegistrationByEnvelopeId($envelopeId);

        if ($registration === null) {
            Log::warning('ProcessDocuSignWebhookJob: no registration found for envelope', [
                'envelope_id' => $envelopeId,
            ]);

            return;
        }

        try {
            $actaSigned = $docuSignService->downloadSignedDocument($registration, $envelopeId);

            // Update the ACTA_FINAL sign_status to completed.
            $actaFinal = $registration->documents()
                ->where('type', DocumentTypeEnum::ACTA_FINAL)
                ->latest()
                ->first();

            if ($actaFinal !== null) {
                $templateData = $actaFinal->template_data ?? [];
                $templateData['sign_status']['status'] = 'completed';
                $templateData['sign_status']['completed_at'] = now()->toIso8601String();
                $actaFinal->update(['template_data' => $templateData]);
            }

            Log::info('ProcessDocuSignWebhookJob: envelope completed — signed PDF saved', [
                'registration_id' => $registration->id,
                'acta_signed_id' => $actaSigned->id,
            ]);

        } catch (\Throwable $e) {
            Log::error('ProcessDocuSignWebhookJob: failed to download signed document', [
                'envelope_id' => $envelopeId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle envelope.voided — the envelope was cancelled before completion.
     *
     * Updates the ACTA_FINAL sign_status so the notary can see the void reason
     * and decide whether to re-send.
     *
     * @param  string  $envelopeId  The voided envelope.
     */
    private function handleEnvelopeVoided(string $envelopeId): void
    {
        $registration = $this->findRegistrationByEnvelopeId($envelopeId);

        if ($registration === null) {
            return;
        }

        $voidReason = $this->payload['data']['envelopeSummary']['voidedReason'] ?? 'unknown';

        $actaFinal = $registration->documents()
            ->where('type', DocumentTypeEnum::ACTA_FINAL)
            ->latest()
            ->first();

        if ($actaFinal !== null) {
            $templateData = $actaFinal->template_data ?? [];
            $templateData['sign_status']['status'] = 'voided';
            $templateData['sign_status']['voided_at'] = now()->toIso8601String();
            $templateData['sign_status']['void_reason'] = $voidReason;
            $actaFinal->update(['template_data' => $templateData]);
        }

        Log::warning('ProcessDocuSignWebhookJob: envelope voided', [
            'registration_id' => $registration->id,
            'envelope_id' => $envelopeId,
            'reason' => $voidReason,
        ]);
    }

    /**
     * Handle recipient.completed — one signer has signed.
     *
     * Updates the individual signer's status in ACTA_FINAL.template_data.sign_status.signer_status
     * so the notary can see real-time progress in the dashboard.
     *
     * @param  string  $envelopeId  The envelope where a recipient completed.
     */
    private function handleRecipientCompleted(string $envelopeId): void
    {
        $registration = $this->findRegistrationByEnvelopeId($envelopeId);

        if ($registration === null) {
            return;
        }

        $recipientEmail = $this->payload['data']['recipientSummary']['email'] ?? null;

        if ($recipientEmail === null) {
            return;
        }

        $actaFinal = $registration->documents()
            ->where('type', DocumentTypeEnum::ACTA_FINAL)
            ->latest()
            ->first();

        if ($actaFinal === null) {
            return;
        }

        // Find the matching signer in the anchor_map and update their status.
        $templateData = $actaFinal->template_data ?? [];
        $anchorMap = $templateData['anchor_map'] ?? [];

        foreach ($anchorMap as $key => $signerInfo) {
            if (strtolower($signerInfo['email']) === strtolower($recipientEmail)) {
                $templateData['sign_status']['signer_status'][$key]['status'] = 'completed';
                $templateData['sign_status']['signer_status'][$key]['signed_at'] = now()->toIso8601String();
                break;
            }
        }

        $actaFinal->update(['template_data' => $templateData]);

        Log::info('ProcessDocuSignWebhookJob: recipient signed', [
            'registration_id' => $registration->id,
            'email' => $recipientEmail,
        ]);
    }

    /**
     * Find a Registration by looking up the envelope_id stored in an ACTA_FINAL document.
     *
     * DocuSign webhooks only include the envelope_id; we find our registration by scanning
     * ACTA_FINAL documents whose template_data->sign_status->envelope_id matches.
     *
     * @param  string  $envelopeId  DocuSign envelope ID to look up.
     * @return Registration|null The matching registration, or null if not found.
     */
    private function findRegistrationByEnvelopeId(string $envelopeId): ?Registration
    {
        // Use a JSON path query to find the document with the matching envelope_id.
        // Supported by MySQL 5.7+ and MariaDB 10.2+.
        $document = Document::where('type', DocumentTypeEnum::ACTA_FINAL->value)
            ->whereJsonPath('template_data->sign_status->envelope_id', $envelopeId)
            ->first();

        return $document?->registration;
    }
}
