<?php

namespace App\Jobs;

use App\Enums\LegalNameStatusEnum;
use App\Enums\WebhookEventStatusEnum;
use App\Models\LegalName;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\NewExpedienteReceived;
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
     * @param  SingapurSubmissionParser   $parser         Parses the raw payload into a DTO.
     * @param  RegistrationUpsertService  $upsertService  Creates or updates the registration.
     *
     * @return void
     *
     * @throws \RuntimeException  When the payload is missing required submission fields.
     */
    public function handle(
        SingapurSubmissionParser $parser,
        RegistrationUpsertService $upsertService,
    ): void {
        $submission   = $parser->parse($this->webhookEvent->payload);
        $registration = $upsertService->upsert($submission);

        // Notify every super_admin so they can review the new expedient immediately.
        User::role('super_admin')->each(
            fn (User $admin) => $admin->notify(new NewExpedienteReceived($registration))
        );

        // Attempt immediate MUA submission for every denomination in WAIT status.
        // SubmitLegalNameToMuaJob checks business hours and FIEL availability;
        // if conditions are not met it exits cleanly and the cron retries later.
        $registration->legalNames()
            ->where('status', LegalNameStatusEnum::WAIT->value)
            ->whereNull('mua_account_id')
            ->each(function (LegalName $legalName): void {
                SubmitLegalNameToMuaJob::dispatch($legalName->id);
            });

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
