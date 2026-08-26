<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\LegalNameEventTypeEnum;
use App\Enums\LegalNameStatusEnum;
use App\Models\LegalName;
use App\Services\LegalName\CheckMuaAvailabilityService;
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

        // The SE refuses a trámite for a name already on its registry, and does so
        // WITHOUT saying the name is not viable — so the bot reads it as a technical
        // fault and the denomination is retried forever. Catch it here instead: this
        // is the public registry, no login needed, and it costs one request.
        // Only a definite `false` blocks; an unreachable portal must not.
        if (app(CheckMuaAvailabilityService::class)->check($legalName->name) === false) {
            $legalName->update([
                'status' => LegalNameStatusEnum::REJECTED->value,
                'rejection_reason' => 'La denominación ya está registrada en la SE, '
                    .'así que el portal no permite solicitarla.',
            ]);

            $legalName->recordEvent(
                LegalNameEventTypeEnum::REJECTED,
                'No se envió: la razón social ya está registrada en la SE.',
                ['origen' => 'consulta pública del MUA'],
            );

            Log::info('SubmitDenominationToMuaNowJob: name already registered at the SE — not sent.', [
                'legal_name_id' => $this->legalNameId,
                'name' => $legalName->name,
            ]);

            return;
        }

        try {
            if ($service->trySubmit($legalName)) {
                return;
            }

            // Deferred, not failed: outside business hours, or every FIEL is holding
            // an in-process denomination. trySubmit() already recorded the FIEL case;
            // record the hours case here so a name queued off-hours never sits in the
            // pool with no explanation of why nothing happened.
            if (! $service->isBusinessHours()) {
                $legalName->recordEvent(
                    LegalNameEventTypeEnum::DEFERRED,
                    'Envío diferido — quedó en espera.',
                    ['reason' => $service->unavailabilityReason()],
                );
            }

            if ($legalName->status !== LegalNameStatusEnum::WAIT) {
                $legalName->update(['status' => LegalNameStatusEnum::WAIT->value]);
            }
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
