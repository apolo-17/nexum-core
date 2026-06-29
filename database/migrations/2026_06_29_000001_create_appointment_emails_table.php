<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the appointment_emails pool — the catalog of mailbox addresses the SAT bot
 * uses to receive the appointment token.
 *
 * Nexum owns this catalog (which alias is free/in use). The SAT bot is handed an alias
 * per appointment via /sat-bot/pending and reads the shared mailbox (IMAP) to extract
 * the token. The mailbox credential lives in the bot, never here.
 *
 * Mirrors Tally's dating_emails table.
 */
return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        Schema::create('appointment_emails', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('address')->unique();
            $table->boolean('is_free')->default(true)
                ->comment('Available to be assigned to a pending appointment');
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointment_emails');
    }
};
