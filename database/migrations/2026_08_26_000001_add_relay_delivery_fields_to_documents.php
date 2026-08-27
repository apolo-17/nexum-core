<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks delivery of a deliverable document to the China relay.
 *
 * When RelayDocumentAlertService successfully alerts the relay and China confirms it stored
 * the file, we stamp relay_delivered_at and keep the Google Drive link China returned, so the
 * panel can show, per registration, which deliverables China already has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->timestamp('relay_delivered_at')->nullable()->after('template_data')
                ->comment('When China confirmed it received/stored this deliverable.');
            $table->string('relay_drive_url')->nullable()->after('relay_delivered_at')
                ->comment("China's Google Drive web view link for this delivered document.");
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn(['relay_delivered_at', 'relay_drive_url']);
        });
    }
};
