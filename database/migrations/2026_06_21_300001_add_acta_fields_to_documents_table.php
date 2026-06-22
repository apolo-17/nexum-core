<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the documents table with two columns needed for acta preparation.
 *
 * shareholder_index — links a KYC document to the 1-based shareholder position
 *   from the Singapur relay (naturalPassport1 → index 1). Nullable because
 *   manually uploaded documents and acta drafts are not shareholder-specific.
 *
 * template_data — JSON payload of the compiled acta constitutiva fields for
 *   documents of type acta_draft. Mirrors Tally's template_data pattern and
 *   serves as the source of truth when generating the final DOCX/PDF.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            // 1-based index matching the shareholder position in the relay (e.g. 1, 2, 3).
            $table->unsignedSmallInteger('shareholder_index')->nullable()->after('stage');

            // Compiled template data for acta_draft documents.
            $table->json('template_data')->nullable()->after('shareholder_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn(['shareholder_index', 'template_data']);
        });
    }
};
