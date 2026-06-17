<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds relay-tracking columns needed for lazy document download.
 *
 * registrations.singapur_folder_name — the folder identifier used to request
 *   the document ZIP from the Singapur relay API (e.g. '000001_NOVA CONSULTORA EMPRESARIAL').
 *
 * documents.relay_zip_path — the entry path within the ZIP archive for this
 *   specific file (e.g. 'KYC/shareholder_1/000001__...pdf'). Used by
 *   SingapurRelayService to extract only the requested file without unpacking
 *   the entire archive.
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

        Schema::table('documents', function (Blueprint $table) {
            $table->string('relay_zip_path')
                ->nullable()
                ->after('name')
                ->comment('Entry path within the relay ZIP archive (e.g. KYC/shareholder_1/relay_name.pdf)');
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

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('relay_zip_path');
        });
    }
};
