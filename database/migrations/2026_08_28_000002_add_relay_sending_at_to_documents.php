<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a deliverable as "currently being sent to China" from the moment the send is dispatched
 * (on upload or on a manual click) until it resolves. The "Entregables a China" panel reads this
 * to show a live "Enviando…" state with disabled buttons, and to auto-refresh — so the operator
 * never sees a misleading "falta enviar" for a document that is actually on its way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dateTime('relay_sending_at')->nullable()->after('relay_last_error');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn('relay_sending_at');
        });
    }
};
