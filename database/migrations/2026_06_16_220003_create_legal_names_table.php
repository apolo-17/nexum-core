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
        Schema::create('legal_names', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignUlid('registration_id')->constrained('registrations')->cascadeOnDelete();

            $table->string('name')->comment('Proposed company denomination');
            $table->unsignedTinyInteger('priority')->comment('Preference order 1 to 4');
            $table->string('status')->default('wait')->comment('wait, pending, process, approved, rejected');

            // Assigned by SE (Secretaría de Economía) upon approval
            $table->string('clave_unica_denominacion')->nullable()->comment('Unique key assigned by SE');
            $table->dateTime('authorization_timestamp')->nullable()->comment('Timestamp when SE authorized the denomination');
            $table->dateTime('submitted_at')->nullable()->comment('Timestamp when the denomination was submitted for review');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legal_names');
    }
};
