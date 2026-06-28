<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the appointments table — the SAT appointments each company needs.
 *
 * Every registration needs two appointments: one for the RFC and one for the FIEL
 * (e.firma). Captured manually for now; the SAT bot (separate service) will later
 * fill scheduled_at / office / status via a callback, mirroring the MUA bot.
 *
 * Not unique per (registration, type): a rejected or no-showed appointment is kept
 * for history and a fresh one is created for the reschedule.
 */
return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('registration_id')->constrained('registrations')->cascadeOnDelete();
            $table->foreignUlid('soldado_id')->nullable()->constrained('soldados')->nullOnDelete()
                ->comment('Soldado who attends the appointment, if assigned');

            $table->string('type')->comment('rfc | fiel');
            $table->string('status')->default('pending_scheduling')
                ->comment('EfirmaAppointmentStatusEnum');
            $table->dateTime('scheduled_at')->nullable();
            $table->string('office')->nullable()->comment('SAT office / sede');
            $table->string('acknowledgment_path')->nullable()->comment('Acuse storage path (R2)');
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['registration_id', 'type']);
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
