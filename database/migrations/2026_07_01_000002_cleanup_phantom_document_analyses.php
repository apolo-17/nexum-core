<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-off cleanup: delete phantom DocumentAnalysis rows that belong to
 * non-analysable documents (actas, renders, etc.).
 *
 * Before the gating fix, AnalyzeDocumentJob created a "processing" analysis row
 * before checking the document type, so non-KYC documents ended up with a stuck
 * "en proceso" IA spinner. This runs automatically on deploy (php artisan migrate),
 * so no manual command or tinker access is needed.
 */
return new class extends Migration
{
    /**
     * Analysable document types — the only ones that legitimately have an analysis.
     *
     * @var list<string>
     */
    private array $analysable = [
        'passport',
        'kyc_tax_certificate',
        'kyc_proof_of_address',
        'kyc_marriage_certificate',
        'kyc_spouse_passport',
    ];

    /**
     * Delete analysis rows whose document type is not analysable (or whose document is gone).
     */
    public function up(): void
    {
        DB::table('document_analyses')
            ->whereNotIn('document_id', function ($query): void {
                $query->select('id')
                    ->from('documents')
                    ->whereIn('type', $this->analysable);
            })
            ->delete();
    }

    /**
     * Irreversible data cleanup — nothing to restore.
     */
    public function down(): void
    {
        // No-op: deleted phantom records cannot (and should not) be recreated.
    }
};
