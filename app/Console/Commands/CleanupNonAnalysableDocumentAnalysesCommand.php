<?php

namespace App\Console\Commands;

use App\Models\DocumentAnalysis;
use App\Services\Document\DocumentAnalysisService;
use Illuminate\Console\Command;

/**
 * Deletes phantom DocumentAnalysis records that belong to non-analysable documents.
 *
 * Before the gating fix, AnalyzeDocumentJob created a "processing" DocumentAnalysis
 * row before checking the document type, so actas/renders and other non-KYC documents
 * ended up with a stuck "en proceso" IA spinner in the dashboard. This command removes
 * those orphan records. It is idempotent and safe to run repeatedly.
 */
class CleanupNonAnalysableDocumentAnalysesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'documents:cleanup-ia-analyses';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Borra registros de análisis IA de documentos que no son analizables (actas, renders, etc.)';

    /**
     * Execute the console command.
     *
     * @param  DocumentAnalysisService  $service  Used to reuse the single source of truth for analysability.
     */
    public function handle(DocumentAnalysisService $service): int
    {
        $deleted = 0;

        DocumentAnalysis::with('document')
            ->chunkById(200, function ($analyses) use ($service, &$deleted): void {
                foreach ($analyses as $analysis) {
                    $document = $analysis->document;

                    // Delete when the document is gone or its type is not analysable.
                    if ($document === null || ! $service->isAnalysable($document)) {
                        $analysis->delete();
                        $deleted++;
                    }
                }
            });

        $this->info("Registros de análisis IA eliminados: {$deleted}");

        return self::SUCCESS;
    }
}
