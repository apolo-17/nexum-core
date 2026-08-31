<?php

namespace App\Jobs;

use App\Enums\DocumentTypeEnum;
use App\Models\Document;
use App\Models\Registration;
use App\Models\User;
use App\Services\Registration\ActaExtractionService;
use App\Services\Registration\ApoderadoReconciliationService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Reads a company's acta protocolizada with AI, recovers the parties, and links the fiscal
 * attorneys we recognize (by RFC) to the expediente so they become selectable for SAT appointments.
 *
 * This is how a company that only has its acta uploaded gets its legal representatives back: the
 * acta is the notarized source of truth. The result (parties + RFC reconciliation) is stored on the
 * registration and summarized to the super admins, so a missing or unknown apoderado is visible.
 *
 * Runs on the queue because compressing a 35 MB scan and calling Claude vision takes well over a
 * web request's budget.
 */
class ExtractActaPartiesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    /**
     * @param  string  $registrationId  The expediente whose acta protocolizada should be read.
     */
    public function __construct(private readonly string $registrationId) {}

    public function handle(ActaExtractionService $extractor, ApoderadoReconciliationService $reconciler): void
    {
        $registration = Registration::find($this->registrationId);
        if ($registration === null) {
            return;
        }

        $acta = Document::query()
            ->where('registration_id', $registration->id)
            ->where('type', DocumentTypeEnum::ACTA_PROTOCOLIZADA)
            ->whereNotNull('storage_path')
            ->latest()
            ->first();

        if ($acta === null) {
            return;
        }

        $extraction = $extractor->extract($acta);

        // The model must confirm this really is a protocolized deed before we trust its parties.
        if (! ($extraction['is_acta_protocolizada'] ?? false)) {
            $reason = $extraction['reason_if_not'] ?? 'El documento no parece un acta protocolizada.';
            $this->persist($registration, $extraction, null, extractedOk: false);
            $this->notify($registration, "⚠️ El acta de {$this->name($registration)} no se validó como acta protocolizada: {$reason}");

            return;
        }

        $reconciliation = $reconciler->reconcile(
            $registration,
            (array) ($extraction['apoderados_fiscales'] ?? []),
            (array) ($extraction['socios'] ?? []),
        );

        $this->persist($registration, $extraction, $reconciliation, extractedOk: true);

        $matched = count($reconciliation['apoderados_matched']);
        $missing = count($reconciliation['apoderados_not_found']);
        $summary = "Acta de {$this->name($registration)}: {$matched} apoderado(s) ligado(s) al expediente";
        if ($missing > 0) {
            $summary .= ", {$missing} sin encontrar en el sistema (revisar)";
        }
        $this->notify($registration, $summary.'.');

        Log::info('ExtractActaPartiesJob: acta reconciled', [
            'registration_id' => $registration->id,
            'matched' => $matched,
            'not_found' => $missing,
        ]);
    }

    /**
     * Store the extraction + reconciliation on the registration for the expediente to render.
     */
    private function persist(Registration $registration, array $extraction, ?array $reconciliation, bool $extractedOk): void
    {
        $registration->forceFill([
            'acta_extraction' => [
                'ok' => $extractedOk,
                'extracted_at' => now()->toIso8601String(),
                'denominacion' => $extraction['denominacion'] ?? null,
                'escritura' => $extraction['escritura'] ?? null,
                'socios' => $extraction['socios'] ?? [],
                'apoderados_fiscales' => $extraction['apoderados_fiscales'] ?? [],
                'confidence' => $extraction['confidence'] ?? null,
                'reason_if_not' => $extraction['reason_if_not'] ?? null,
                'reconciliation' => $reconciliation,
            ],
        ])->save();
    }

    /**
     * Send a database (bell) notification to every super admin.
     */
    private function notify(Registration $registration, string $body): void
    {
        try {
            $admins = User::role('super_admin')->get();
            if ($admins->isEmpty()) {
                return;
            }

            Notification::make()
                ->title('Extracción de socios del acta')
                ->body($body)
                ->info()
                ->sendToDatabase($admins);
        } catch (\Throwable $e) {
            Log::warning('ExtractActaPartiesJob: could not notify.', ['error' => $e->getMessage()]);
        }
    }

    private function name(Registration $registration): string
    {
        return (string) ($registration->singapur_folder_name ?: $registration->singapur_client_code ?: $registration->id);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ExtractActaPartiesJob failed', [
            'registration_id' => $this->registrationId,
            'error' => $exception->getMessage(),
        ]);
    }
}
