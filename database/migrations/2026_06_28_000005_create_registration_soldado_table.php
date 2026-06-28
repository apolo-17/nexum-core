<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the registration_soldado pivot — soldados acting in an incorporation act.
 *
 * Replaces legal_agent_registration. Unlike the old pivot, the role (legal
 * representative / commissary) lives on the pivot, not on the person, so a single
 * soldado who is flagged for both capabilities can act in either role per acta.
 * participation_percentage is preserved per acta.
 */
return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        Schema::create('registration_soldado', function (Blueprint $table): void {
            $table->foreignUlid('soldado_id')->constrained('soldados')->cascadeOnDelete();
            $table->foreignUlid('registration_id')->constrained('registrations')->cascadeOnDelete();
            $table->string('role')->nullable()->comment('legal_representative | commissary');
            $table->decimal('participation_percentage', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['soldado_id', 'registration_id']);
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('registration_soldado');
    }
};
