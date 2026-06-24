<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the legal_agents catalog — reusable legal representatives and commissaries.
 *
 * These profiles are assigned manually to actas constitutivas (registrations) via the
 * legal_agent_registration pivot, each with the share percentage they hold in that acta.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('legal_agents', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('type')->comment('legal_representative or commissary — see LegalAgentTypeEnum');
            $table->string('name')->comment('Full legal name');
            $table->string('nationality')->nullable()->comment('Country of nationality');
            $table->string('rfc', 13)->nullable()->comment('Mexican RFC (foreigners use the generic EXTF900101NI1)');
            $table->string('curp', 18)->nullable()->comment('Mexican CURP, when applicable');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->date('birthdate')->nullable();
            $table->string('birthplace')->nullable()->comment('City and country of birth');
            $table->text('address')->nullable()->comment('Full residential address for the acta');
            $table->text('notes')->nullable()->comment('Internal notes for the notary team');
            $table->boolean('is_active')->default(true)->comment('Inactive agents are hidden from new assignments without deleting history');

            $table->timestamps();
            $table->softDeletes();

            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legal_agents');
    }
};
