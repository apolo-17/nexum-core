<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DocumentTypeEnum;
use App\Models\Document;
use App\Models\Registration;
use App\Models\Shareholder;
use App\Models\User;
use App\Notifications\ActaRenderIncomplete;
use App\Notifications\ActaRenderReady;
use App\Services\Registration\ActaCompletenessValidator;
use App\Services\Registration\ActaPreparationService;
use App\Services\Registration\KycReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Builds a company's acta render (ACTA_DRAFT) in the background as soon as its documentation
 * and payload have arrived — without waiting for KYC validation or a stage change.
 *
 * Flow:
 *   1. Completeness check (ActaCompletenessValidator). If anything critical is missing —
 *      a socio without a passport, no folio, too few apoderados — notify the team with the
 *      exact list and STOP. They fill the gap and re-run.
 *   2. KYC reconciliation (best-effort): adopt the passport's official name/order where it is
 *      clearly the same person, and collect warnings for anything to double-check.
 *   3. Compile the template data and upsert the ACTA_DRAFT document.
 *   4. Notify the team the acta is ready (with any warnings).
 */
class BuildActaRenderJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Retry once on transient failures (e.g. the vision API); alert instead of dying. */
    public int $tries = 2;

    public function __construct(
        public readonly string $registrationId,
    ) {}

    public function handle(
        ActaCompletenessValidator $completeness,
        KycReconciliationService $reconciliation,
        ActaPreparationService $preparation,
    ): void {
        $registration = Registration::find($this->registrationId);

        if ($registration === null) {
            return;
        }

        $issues = $completeness->validate($registration);

        if ($issues !== []) {
            $this->notifyAdmins(new ActaRenderIncomplete($registration, $issues));

            return;
        }

        $warnings = $this->reconcileKyc($registration, $reconciliation);

        $registration->refresh();
        $data = $preparation->compile($registration);

        Document::updateOrCreate(
            [
                'registration_id' => $registration->id,
                'type' => DocumentTypeEnum::ACTA_DRAFT,
            ],
            [
                'name' => "Borrador Acta - {$registration->singapur_client_code}",
                'template_data' => $data,
                'stage' => $registration->getRawOriginal('stage'),
            ],
        );

        $this->notifyAdmins(new ActaRenderReady($registration, $warnings));
    }

    /**
     * Run the best-effort KYC reconciliation, adopting official passport names and returning
     * the warnings. Never throws — a vision failure must not stop the render.
     *
     * @return list<string> Non-blocking review warnings.
     */
    private function reconcileKyc(Registration $registration, KycReconciliationService $reconciliation): array
    {
        try {
            $result = $reconciliation->reconcile($registration);

            foreach ($result->officialNames as $shareholderId => $officialName) {
                Shareholder::whereKey($shareholderId)->update(['name' => $officialName]);
            }

            return $result->warnings;
        } catch (\Throwable $exception) {
            Log::warning('BuildActaRenderJob: KYC reconciliation failed (continuing).', [
                'registration_id' => $registration->id,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function notifyAdmins(object $notification): void
    {
        $admins = User::role('super_admin')->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, $notification);
        }
    }
}
