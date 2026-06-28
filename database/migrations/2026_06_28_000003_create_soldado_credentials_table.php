<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the soldado_credentials table — encrypted FIEL (e.firma) components.
 *
 * Mirrors mua_credentials: one row per credential type (certificate, private_key,
 * password). Values are encrypted at rest via the Crypt facade. Only required when
 * the soldado has available_for_mua = true.
 */
return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        Schema::create('soldado_credentials', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('soldado_id')->constrained('soldados')->cascadeOnDelete();
            $table->string('type')->comment('certificate | private_key | password');
            $table->string('credential', 4000)->comment('Encrypted value (Crypt)');
            $table->timestamps();

            $table->unique(['soldado_id', 'type']);
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('soldado_credentials');
    }
};
