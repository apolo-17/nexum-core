<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add last_status_check_at to legal_names.
 *
 * Records when a manual status check was last requested to the MUA bot. Drives
 * the "Consultando…" loading indicator on the denomination detail view while a
 * check is in flight and no fresher resolution has arrived via the callback.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('legal_names', function (Blueprint $table) {
            $table->dateTime('last_status_check_at')
                ->nullable()
                ->after('submitted_at')
                ->comment('When a manual SE status check was last requested to the bot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('legal_names', function (Blueprint $table) {
            $table->dropColumn('last_status_check_at');
        });
    }
};
