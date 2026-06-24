<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot linking legal agents (representatives / commissaries) to actas (registrations).
 *
 * Each assignment carries the share percentage the agent holds in that specific acta,
 * set manually by the notary inside the acta render. A given agent can appear in many
 * actas with a different percentage in each, and an acta can have several agents.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('legal_agent_registration', function (Blueprint $table) {
            // Standard pivot — no surrogate key; attach()/sync() insert rows directly
            // (which would not populate a ULID). Integrity is enforced by the unique
            // constraint on (legal_agent_id, registration_id) below.
            $table->foreignUlid('legal_agent_id')->constrained('legal_agents')->cascadeOnDelete();
            $table->foreignUlid('registration_id')->constrained('registrations')->cascadeOnDelete();

            $table->decimal('participation_percentage', 5, 2)->nullable()
                ->comment('Share percentage this agent holds in this acta — assigned manually by the notary');

            $table->timestamps();

            // An agent is assigned at most once per acta.
            $table->unique(['legal_agent_id', 'registration_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legal_agent_registration');
    }
};
