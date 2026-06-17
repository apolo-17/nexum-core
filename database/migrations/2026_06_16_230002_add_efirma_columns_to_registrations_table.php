<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds e.firma appointment tracking columns to the registrations table.
 *
 * Tracks the outcome of the SAT e.firma appointment (requested, scheduled,
 * approved, rejected, or no-show) and stores the paths to the client's
 * e.firma credential files (.key, .cer) once successfully issued.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->string('efirma_status')
                ->nullable()
                ->after('efirma_appointment_at')
                ->comment('e.firma appointment status: pending_scheduling, scheduled, attended_approved, attended_rejected, no_show');

            $table->string('efirma_key_path')
                ->nullable()
                ->after('efirma_status')
                ->comment('Storage path for the client .key file uploaded after a successful e.firma appointment');

            $table->string('efirma_cer_path')
                ->nullable()
                ->after('efirma_key_path')
                ->comment('Storage path for the client .cer file uploaded after a successful e.firma appointment');

            $table->string('efirma_password_hash')
                ->nullable()
                ->after('efirma_cer_path')
                ->comment('Bcrypt hash of the e.firma password — stored hashed, never in plain text');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropColumn([
                'efirma_status',
                'efirma_key_path',
                'efirma_cer_path',
                'efirma_password_hash',
            ]);
        });
    }
};
