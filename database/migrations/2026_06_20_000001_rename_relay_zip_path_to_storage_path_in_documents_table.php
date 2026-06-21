<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renames relay_zip_path to storage_path in the documents table.
 *
 * The original column was named after the relay ZIP download flow, which has
 * been replaced by inline base64 content delivery. The column now stores the
 * R2/local storage path where the file was persisted from the webhook payload.
 * Renaming it removes the misleading reference to a ZIP that no longer exists.
 *
 * Coordinate with the project 360 SOT before running on shared environments.
 */
return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->renameColumn('relay_zip_path', 'storage_path');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->renameColumn('storage_path', 'relay_zip_path');
        });
    }
};
