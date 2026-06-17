<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('registrations', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Singapur relay identifiers
            $table->string('singapur_client_code')->unique()->comment('Client identifier in the Singapur relay system');
            $table->string('singapur_package_id')->nullable()->comment('ZIP package ID received from the relay');

            // Current process stage and status
            $table->string('stage')->default('data_received')->comment('Current stage: data_received, identity_validation, legal_name, incorporation, bank_account, sat_registration, efirma_appointment, completed');
            $table->string('status')->default('active')->comment('Overall status: active, on_hold, cancelled, completed');

            // Assignment
            $table->foreignId('assigned_notario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_asistente_id')->nullable()->constrained('users')->nullOnDelete();

            // Company data filled progressively
            $table->string('company_type')->nullable()->comment('SA de CV, SRL de CV, SAPI de CV');
            $table->string('rfc', 13)->nullable()->comment('RFC assigned by SAT');
            $table->dateTime('efirma_appointment_at')->nullable()->comment('Scheduled appointment at SAT for e.firma');

            // Cached counters to avoid frequent queries on the dashboard
            $table->unsignedInteger('notes_count')->default(0);
            $table->unsignedInteger('tasks_pending_count')->default(0);

            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registrations');
    }
};
