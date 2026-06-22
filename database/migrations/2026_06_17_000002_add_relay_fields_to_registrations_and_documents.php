<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds singapur_folder_name to registrations.
 *
 * registrations.singapur_folder_name — the folder identifier used to request
 *   the document ZIP from the Singapur relay API (e.g. '000001_NOVA CONSULTORA EMPRESARIAL').
 *
 * NOTE: documents.relay_zip_path was previously added here but has been removed.
 * storage_path (its successor) is now defined directly in the create_documents_table
 * migration. The rename migration (2026_06_20) is consequently a no-op.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->string('singapur_folder_name')
                ->nullable()
                ->after('singapur_package_id')
                ->comment('Relay folder name used to request the document ZIP (e.g. 000001_NOVA CONSULTORA EMPRESARIAL)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropColumn('singapur_folder_name');
        });
    }
};
