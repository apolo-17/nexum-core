<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\LegalNameEventTypeEnum;
use App\Models\LegalName;
use App\Services\Mua\MuaSubmissionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Submit a denomination to the MUA bot on the operator's request, in the background.
 *
 * The "Enviar a la SE" button used to call trySubmit() synchronously, which blocked the
 * modal while the bot worked (~1 min). This runs the same submission in the queue so the
 * modal closes instantly; the real outcome arrives through the MUA webhook.
 *
 * Unlike SubmitLegalNameToMuaJob (which gates on WAIT for the automated flow), this is
 * the manual button, so it does not gate on status — it mirrors DenominationResource's
 * attemptSubmit(): trySubmit(), and on error record the failure on the timeline.
 */
class SubmitDenominationToMuaNowJob implements ShouldQueue
{
    use Queueable;

    /**
     * Only attempt once; the cron (mua:submit) retries deferred denominations.
     */
    public int $tries = 1;

    /**
     * @param  string  $legalNameId  ULID of the denomination to submit.
     */
    public function __construct(
        private readonly string $legalNameId,
    ) {}

    /**
     * Execute the job — submit the denomination to the MUA bot.
     *
     * @param  MuaSubmissionService  $service  Injected submission service.
     */
    public function handle(MuaSubmissionService $service): void
    {
        $legalName = LegalName::find($this->legalNameId);

        if ($legalName === null) {
            Log::warning('SubmitDenominationToMuaNowJob: denomination not found — skipping.', [
                'legal_name_id' => $this->legalNameId,
            ]);

            return;
        }

        try {
            $service->trySubmit($legalName);
        } catch (\Throwable $exception) {
            $legalName->recordEvent(
                LegalNameEventTypeEnum::SUBMISSION_FAILED,
                'Error al enviar al portal MUA.',
                ['error' => $exception->getMessage()],
            );

            Log::error('SubmitDenominationToMuaNowJob: submission failed.', [
                'legal_name_id' => $this->legalNameId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
