<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only timeline for SAT appointments.
 *
 * Mirrors legal_name_events (the MUA bot's history): every time the bot forms or reviews
 * an appointment it leaves a record, so the team can see whether it has actually been
 * working on it and what the SAT answered each time — instead of only the last status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('appointment_id')
                ->constrained('appointments')
                ->cascadeOnDelete();
            $table->string('type')->comment('Event type — see AppointmentEventTypeEnum');
            $table->string('actor_type')->nullable()->comment('user, system or bot');
            // users.id is a bigint (not a ULID like appointments), so this has to match
            // its type — a foreignUlid here cannot reference it.
            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('description')->nullable()->comment('Human-readable summary');
            $table->json('metadata')->nullable()->comment('Event-specific payload (office, reason, etc.)');

            $table->timestamp('created_at')->useCurrent();

            $table->index(['appointment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_events');
    }
};
