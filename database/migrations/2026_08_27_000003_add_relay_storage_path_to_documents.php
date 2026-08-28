<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A compressed derivative of a deliverable document, served to the China/Singapur
 * relay in place of the (possibly huge) original.
 *
 * Scanned actas run 30-37 MB — above China's per-document limit and Cloud Run's
 * request ceiling — so the relay pulls a Ghostscript-compressed copy stored here,
 * while the original stays untouched for internal use. Null means "serve the original".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('relay_storage_path')->nullable()->after('relay_rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn('relay_storage_path');
        });
    }
};
