<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit log of every milestone in a denomination's lifecycle.
 *
 * Mirrors the stage_transitions pattern used for registrations: each row is an
 * immutable event (created → submitted → in process → approved/rejected, plus
 * deferrals and submission errors) carrying an actor and arbitrary metadata.
 * Only created_at is tracked; the records are never updated.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('legal_name_events', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('legal_name_id')
                ->constrained('legal_names')
                ->cascadeOnDelete();

            $table->string('type')->comment('Event type — see LegalNameEventTypeEnum');

            // Who triggered the event: a dashboard user, the system (cron/job) or the bot.
            $table->string('actor_type')->nullable()->comment('user, system or bot');
            $table->foreignUlid('actor_id')
                ->nullable()
                ->comment('User ULID when actor_type = user');

            $table->string('description')->nullable()->comment('Human-readable summary');
            $table->json('metadata')->nullable()->comment('Event-specific payload (FIEL, folio, error, etc.)');

            // Immutable record — no updated_at needed.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['legal_name_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legal_name_events');
    }
};
