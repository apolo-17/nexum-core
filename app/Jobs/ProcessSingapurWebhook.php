<?php

namespace App\Jobs;

use App\Enums\LegalNameStatusEnum;
use App\Enums\NotificationEventEnum;
use App\Enums\RegistrationStageEnum;
use App\Enums\WebhookEventStatusEnum;
use App\Models\Document;
use App\Models\LegalName;
use App\Models\Registration;
use App\Models\WebhookEvent;
use App\Notifications\ExpedienteReceptionFailed;
use App\Notifications\NewExpedienteReceived;
use App\Services\Notifications\EventNotifier;
use App\Services\Registration\RegistrationUpsertService;
use App\Services\Singapur\SingapurSubmissionParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Processes an incoming webhook event from the Singapur relay asynchronously.
 *
 * The relay now sends the full submission JSON directly in the webhook payload,
 * so this job parses that payload and upserts the Registration with all its
 * related entities without downloading any ZIP. Document files are fetched
 * lazily via SingapurRelayService only when a document stage requires them.
 * On failure the event is marked FAILED for inspection and retry.
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
     * The target queue ('webhooks', monitored by its own Horizon supervisor)
     * is set via onQueue() instead of a $queue property: redeclaring the
     * Queueable trait's $queue property with a different default triggers a
     * fatal trait-composition error on this PHP version.
     *
     * @param  WebhookEvent  $webhookEvent  The persisted event to process.
     */
    public function __construct(
        public readonly WebhookEvent $webhookEvent,
    ) {
        $this->onQueue('webhooks');
    }

    /**
     * Execute the job — parse the webhook payload, upsert the registration, and notify admins.
     *
     * After a successful upsert, dispatches SubmitLegalNameToMuaJob for each new
     * denomination in WAIT status so the submission is attempted immediately if
     * business hours and FIEL availability conditions are met. If not, the cron
     * (mua:submit) will pick the denominations up on the next eligible window.
     *
     * @param  SingapurSubmissionParser  $parser  Parses the raw payload into a DTO.
     * @param  RegistrationUpsertService  $upsertService  Creates or updates the registration.
     * @param  EventNotifier  $notifier  Dispatches the configurable reception notification.
     *
     * @throws \RuntimeException When the payload is missing required submission fields.
     */
    public function handle(
        SingapurSubmissionParser $parser,
        RegistrationUpsertService $upsertService,
        EventNotifier $notifier,
    ): void {
        $submission = $parser->parse($this->webhookEvent->payload);
        $registration = $upsertService->upsert($submission);

        // When China sends the pre-rendered acta (incorporation_deed), identity is
        // already verified and data already extracted on their side: mark every
        // document verified, kick off our own extraction, and skip identity.
        if ($submission->incorporationDeed !== null) {
            $this->handlePreVerifiedSubmission($registration);
        }

        // Notify the recipients configured for the "Recepción de expedientes"
        // event (super_admin only); a no-op when the event is disabled.
        $notifier->notify(
            NotificationEventEnum::EXPEDIENTE_RECEIVED,
            new NewExpedienteReceived($registration),
        );

        // Attempt immediate MUA submission for every denomination in WAIT status.
        // SubmitLegalNameToMuaJob checks business hours and FIEL availability;
        // if conditions are not met it exits cleanly and the cron retries later.
        $registration->legalNames()
            ->where('status', LegalNameStatusEnum::WAIT->value)
            ->whereNull('soldado_id')
            ->each(function (LegalName $legalName): void {
                SubmitLegalNameToMuaJob::dispatch($legalName->id);
            });

        $this->webhookEvent->update([
            'status' => WebhookEventStatusEnum::PROCESSED,
            'processed_at' => now(),
        ]);
    }

    /**
     * Apply the "pre-verified" flow used when China sends the rendered acta.
     *
     * Marks every still-pending document as verified (verified_by stays null to
     * denote a system/relay verification), dispatches Claude extraction for each
     * verified document (non-analysable types are skipped by the service), and
     * advances the registration past identity validation straight to the
     * denomination stage — the only thing left on Nexum's side.
     *
     * @param  Registration  $registration  The freshly upserted registration.
     */
    private function handlePreVerifiedSubmission(Registration $registration): void
    {
        $registration->documents()
            ->whereNull('verified_at')
            ->update(['verified_at' => now(), 'verified_by' => null]);

        $registration->documents()
            ->whereNotNull('verified_at')
            ->get()
            ->each(fn (Document $document) => AnalyzeDocumentJob::dispatch($document));

        // Identity validation happens on China's side; jump to the denomination
        // stage. Guarded so a redelivery never regresses an advanced expedient.
        if ($registration->stage === RegistrationStageEnum::DATA_RECEIVED) {
            $registration->update(['stage' => RegistrationStageEnum::LEGAL_NAME]);
        }
    }

    /**
     * Handle a job failure — record the error message for inspection and retry tracking.
     *
     * @param  Throwable  $exception  The exception that caused the failure.
     */
    public function failed(Throwable $exception): void
    {
        $this->webhookEvent->update([
            'status' => WebhookEventStatusEnum::FAILED,
            'error_message' => $exception->getMessage(),
        ]);

        // Alert the same recipients configured for "Recepción de expedientes" so a
        // failed delivery does not go unnoticed. Resolved at call time (not
        // injected) because failed() runs outside the container's method injection.
        app(EventNotifier::class)->notify(
            NotificationEventEnum::EXPEDIENTE_RECEIVED,
            new ExpedienteReceptionFailed($this->webhookEvent, $exception->getMessage()),
        );
    }
}
