<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rejection state for a deliverable document.
 *
 * When China (or an operator) marks a delivered document as wrong, we stamp relay_rejected_at
 * and keep the (translated) reason, so the panel can flag it and the next upload re-sends it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->timestamp('relay_rejected_at')->nullable()->after('relay_drive_url')
                ->comment('When China/an operator flagged this delivered document as wrong.');
            $table->text('relay_rejection_reason')->nullable()->after('relay_rejected_at')
                ->comment('Why it was rejected (translated to Spanish when it came from China).');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn(['relay_rejected_at', 'relay_rejection_reason']);
        });
    }
};
