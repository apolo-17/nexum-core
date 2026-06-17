<?php

namespace App\Jobs;

use App\Enums\WebhookEventStatusEnum;
use App\Models\WebhookEvent;
use App\Services\Registration\RegistrationUpsertService;
use App\Services\Singapur\SingapurRelayService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Processes an incoming webhook event from the Singapur relay asynchronously.
 *
 * Downloads the submission ZIP from the relay using the company_folder_name in
 * the event payload, parses submission.json, and upserts the Registration with
 * all its related entities. On failure the event is marked FAILED for inspection.
 */
class ProcessSingapurWebhook implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum number of attempts before the job is marked as failed.
     */
    public int $tries = 3;

    /**
     * Wait time in seconds between retry attempts.
     */
    public int $backoff = 60;

    /**
     * Create a new job instance.
     *
     * @param  WebhookEvent  $webhookEvent  The persisted event to process.
     */
    public function __construct(
        public readonly WebhookEvent $webhookEvent,
    ) {}

    /**
     * Execute the job — download the submission package and upsert the registration.
     *
     * The company_folder_name and document_group are extracted from the stored event
     * payload. The relay is called to download the ZIP, submission.json is parsed,
     * and the registration is created or updated via RegistrationUpsertService.
     *
     * @param  SingapurRelayService      $relayService   Downloads and parses the relay ZIP.
     * @param  RegistrationUpsertService $upsertService  Creates or updates the registration.
     * @return void
     *
     * @throws \RuntimeException  When the payload is missing required fields.
     */
    public function handle(
        SingapurRelayService $relayService,
        RegistrationUpsertService $upsertService,
    ): void {
        $payload           = $this->webhookEvent->payload;
        $companyFolderName = $payload['company_folder_name'] ?? null;
        $documentGroup     = $payload['document_group'] ?? 'KYC';

        if (blank($companyFolderName)) {
            throw new \RuntimeException(
                "Webhook event {$this->webhookEvent->event_id} is missing company_folder_name in payload."
            );
        }

        $submission = $relayService->downloadAndParse($companyFolderName, $documentGroup);
        $upsertService->upsert($submission);

        $this->webhookEvent->update([
            'status'       => WebhookEventStatusEnum::PROCESSED,
            'processed_at' => now(),
        ]);
    }

    /**
     * Handle a job failure — record the error message for inspection and retry tracking.
     *
     * @param  Throwable  $exception  The exception that caused the failure.
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        $this->webhookEvent->update([
            'status'        => WebhookEventStatusEnum::FAILED,
            'error_message' => $exception->getMessage(),
        ]);
    }
}
