<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records why a China delivery failed, so the "Entregables a China" panel can show a
 * clear reason (e.g. "excede el límite de China") and offer a resend — instead of the
 * document silently staying in "falta enviar" with no explanation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dateTime('relay_failed_at')->nullable()->after('relay_storage_path');
            $table->text('relay_last_error')->nullable()->after('relay_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn(['relay_failed_at', 'relay_last_error']);
        });
    }
};
