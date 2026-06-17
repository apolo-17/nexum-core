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
        Schema::create('stage_transitions', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('registration_id')->constrained('registrations')->cascadeOnDelete();

            $table->string('from_stage')->comment('Stage before the transition');
            $table->string('to_stage')->comment('Stage after the transition');
            $table->foreignId('performed_by')->constrained('users');
            $table->text('reason')->nullable()->comment('Optional reason for the transition, required on rollbacks');

            // Immutable record — no updated_at needed
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stage_transitions');
    }
};
