<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen soldado_credentials.credential from varchar(4000) to text.
 *
 * Same reason as mua_credentials (see 2026_06_25_000001): the stored value is an
 * encrypted FIEL component (.cer / .key base64 + Laravel encryption envelope), which
 * easily exceeds 4000 characters and Postgres rejects with
 * "value too long for type character varying(4000)". TEXT has no length cap.
 *
 * Named 000005a so it runs AFTER the table is created (000003) and BEFORE the data
 * migration that copies the FIEL values (000006_migrate_mua_and_legal_agents_to_soldados).
 */
return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        Schema::table('soldado_credentials', function (Blueprint $table) {
            $table->text('credential')->comment('Encrypted value (Crypt)')->change();
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('soldado_credentials', function (Blueprint $table) {
            $table->string('credential', 4000)->comment('Encrypted value (Crypt)')->change();
        });
    }
};
