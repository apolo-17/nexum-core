<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\LegalName;
use App\Services\Mua\MuaSubmissionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Attempt an immediate MUA bot submission for a denomination that just arrived from China.
 *
 * Dispatched by ProcessSingapurWebhook right after the Registration and its LegalNames
 * are upserted. If the business-hours gate or FIEL availability check fails, the job
 * exits cleanly without retrying — the cron command (mua:submit) is responsible for
 * picking up WAIT denominations on the next eligible window.
 *
 * Failures from the bot HTTP call are caught and logged but NOT re-thrown, for the same
 * reason: the cron will retry. This makes the job fire-and-forget for the happy path
 * and silently degrading for transient bot errors.
 */
class SubmitLegalNameToMuaJob implements ShouldQueue
{
    use Queueable;

    /**
     * Only attempt once — the cron handles retries for deferred denominations.
     */
    public int $tries = 1;

    /**
     * @param  string  $legalNameId  ULID of the LegalName to submit.
     */
    public function __construct(
        private readonly string $legalNameId,
    ) {}

    /**
     * Execute the job — attempt immediate submission to the MUA bot.
     *
     * Aborts early if the denomination is no longer in WAIT status (e.g. it was
     * already picked up by the cron between dispatch and execution).
     *
     * @param  MuaSubmissionService  $muaSubmissionService  Injected submission service.
     *
     * @return void
     */
    public function handle(MuaSubmissionService $muaSubmissionService): void
    {
        $legalName = LegalName::find($this->legalNameId);

        if ($legalName === null) {
            Log::warning('SubmitLegalNameToMuaJob: LegalName not found — skipping.', [
                'legal_name_id' => $this->legalNameId,
            ]);

            return;
        }

        if (! $legalName->isWaitingForSubmission()) {
            Log::debug('SubmitLegalNameToMuaJob: denomination no longer in WAIT — skipping.', [
                'legal_name_id' => $this->legalNameId,
                'status'        => $legalName->status->value,
            ]);

            return;
        }

        try {
            $submitted = $muaSubmissionService->trySubmit($legalName);

            if (! $submitted) {
                Log::info('SubmitLegalNameToMuaJob: deferred — cron will retry.', [
                    'legal_name_id' => $this->legalNameId,
                ]);
            }
        } catch (\Throwable $e) {
            // Do not re-throw: the cron will pick this up on the next run.
            Log::error('SubmitLegalNameToMuaJob: bot call failed — denomination stays in WAIT.', [
                'legal_name_id' => $this->legalNameId,
                'error'         => $e->getMessage(),
            ]);
        }
    }
}
