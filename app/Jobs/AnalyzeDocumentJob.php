<?php

namespace App\Jobs;

use App\Enums\DocumentTypeEnum;
use App\Models\Document;
use App\Models\DocumentAnalysis;
use App\Models\User;
use App\Services\Document\DocumentAnalysisService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatches Claude vision extraction for a single approved KYC document.
 *
 * The job is dispatched automatically when the notary team approves a document
 * in the Filament dashboard (DocumentsRelationManager). It runs on the 'default'
 * queue and is idempotent: re-running it for the same document simply refreshes
 * the DocumentAnalysis record via updateOrCreate.
 *
 * Supported document types (see DocumentAnalysisService::isAnalysable):
 *   - KYC_PASSPORT / KYC_SPOUSE_PASSPORT / KYC_NATIONAL_IDENTITY → identity fields
 *   - KYC_PROOF_OF_ADDRESS → residential address
 *   - KYC_MARRIAGE_CERTIFICATE → matrimonial regime
 *
 * Documents of other types are silently skipped by the service.
 */
class AnalyzeDocumentJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum number of attempts before the job is considered failed.
     */
    public int $tries = 3;

    /**
     * Number of seconds to wait before retrying a failed attempt.
     */
    public int $backoff = 30;

    /**
     * Create a new job instance.
     *
     * @param  Document  $document  The approved document to analyse.
     */
    public function __construct(
        public readonly Document $document,
    ) {}

    /**
     * Execute the job — call Claude vision and persist the extracted fields.
     *
     * Immediately marks the document as "processing" (analyzed=false, no error)
     * so the dashboard can show a spinner while the API call is in flight.
     * The polling on the Filament table will pick up the state change.
     *
     * @param  DocumentAnalysisService  $service  Injected analysis service.
     */
    public function handle(DocumentAnalysisService $service): void
    {
        // Gate on document type BEFORE touching DocumentAnalysis. Otherwise a
        // non-analysable doc (acta constitutiva, render, etc.) would get a phantom
        // "en proceso" record and show the IA spinner even though no API call runs.
        if (! $service->isAnalysable($this->document)) {
            Log::info('AnalyzeDocumentJob: document type is not analysable — skipped (no analysis record).', [
                'document_id' => $this->document->id,
                'document_type' => $this->document->type->value,
            ]);

            return;
        }

        // Mark as processing before the API call so the UI can show a spinner.
        // analyzed=false with no error_message = "in progress" (distinct from failed).
        DocumentAnalysis::updateOrCreate(
            ['document_id' => $this->document->id],
            ['analyzed' => false, 'error_message' => null],
        );

        Log::info('AnalyzeDocumentJob: starting analysis.', [
            'document_id' => $this->document->id,
            'document_type' => $this->document->type->value,
            'storage_path' => $this->document->storage_path,
        ]);

        $analysis = $service->analyse($this->document);

        if ($analysis === null) {
            Log::info('AnalyzeDocumentJob: document type is not analysable — skipped.', [
                'document_id' => $this->document->id,
                'document_type' => $this->document->type->value,
            ]);

            return;
        }

        Log::info('AnalyzeDocumentJob: analysis complete.', [
            'document_id' => $this->document->id,
            'analysis_id' => $analysis->id,
            'analyzed' => $analysis->analyzed,
        ]);

        $this->warnIfCsfLooksWrong($analysis);
    }

    /**
     * If a document uploaded as a CSF does not actually look like a CSF (per the AI check),
     * flag it to the super admins so they can replace it before it goes to China as the wrong file.
     * Runs on whichever path uploaded it (admin or soldado), since both go through this job.
     */
    private function warnIfCsfLooksWrong(DocumentAnalysis $analysis): void
    {
        if ($this->document->type !== DocumentTypeEnum::CSF) {
            return;
        }

        $raw = $analysis->raw_response;
        // Only warn on an explicit false; a missing flag (older extraction) is not treated as wrong.
        if (! is_array($raw) || ($raw['is_csf'] ?? true) !== false) {
            return;
        }

        Log::warning('AnalyzeDocumentJob: uploaded CSF does not look like a CSF.', [
            'document_id' => $this->document->id,
        ]);

        try {
            $admins = User::role('super_admin')->get();
            if ($admins->isNotEmpty()) {
                Notification::make()
                    ->title('Revisar CSF subida')
                    ->body('El documento subido como CSF de un expediente no parece una Constancia de Situación Fiscal. Verifícalo antes de enviarlo a China.')
                    ->warning()
                    ->sendToDatabase($admins);
            }
        } catch (Throwable $e) {
            Log::warning('AnalyzeDocumentJob: could not notify about wrong CSF.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Handle a job failure — log the error for manual inspection.
     *
     * A failed DocumentAnalysis record is created so the notary team can
     * see in the dashboard that the automatic extraction failed and fill
     * the fields manually.
     *
     * @param  Throwable  $exception  The exception that caused the failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('AnalyzeDocumentJob: failed after all retries.', [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage(),
        ]);

        // Persist a failed analysis record so it is visible in the dashboard.
        DocumentAnalysis::updateOrCreate(
            ['document_id' => $this->document->id],
            [
                'analyzed' => false,
                'error_message' => $exception->getMessage(),
            ],
        );
    }
}
