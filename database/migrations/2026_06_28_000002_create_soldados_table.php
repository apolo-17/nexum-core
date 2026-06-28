<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the soldados table — the single source of truth for a contracted
 * Mexican person used by Nexum.
 *
 * A soldado may act as a MUA operator (lends their FIEL to request denominations)
 * and/or as a legal representative / commissary in the incorporation act. Those are
 * capabilities (flags) of one person, not separate entities. The FIEL is stored once
 * (soldado_credentials) and reused by whichever capability needs it.
 *
 * Login is optional: user_id links to a User account (role `soldado`) only when the
 * super_admin grants dashboard access. The person can exist as a catalog entry
 * without ever logging in.
 *
 * NOTE: data migration from mua_accounts / legal_agents into this table happens in
 * Fase 2, together with re-pointing legal_names and the acta pivot.
 */
return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        Schema::create('soldados', function (Blueprint $table): void {
            $table->ulid('id')->primary();

            // Identity. email/rfc are unique but nullable: a soldado migrated from a
            // legacy legal_agent may lack an email, and foreign reps may have no RFC.
            // (Postgres and SQLite both allow multiple NULLs under a UNIQUE index.)
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone')->nullable();
            $table->string('phone_country_code')->nullable();
            $table->string('rfc', 13)->nullable()->unique();
            $table->string('curp', 18)->nullable();
            $table->date('birthdate')->nullable();
            $table->string('birthplace')->nullable();
            $table->text('address')->nullable();

            // INE (voter ID) — both sides stored on the default disk (R2 in prod).
            $table->string('ine_front_path')->nullable()->comment('INE anverso storage path');
            $table->string('ine_back_path')->nullable()->comment('INE reverso storage path');

            // Capabilities — what this person may be used for.
            $table->boolean('available_for_mua')->default(false)
                ->comment('Lends their FIEL to request denominations on the SE portal');
            $table->boolean('available_as_legal_representative')->default(false);
            $table->boolean('available_as_commissary')->default(false);

            // State
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('active_submissions')->default(0)
                ->comment('MUA denominations currently in PROCESS for this soldado');

            // Optional dashboard login (role `soldado`).
            $table->foreignId('user_id')->nullable()->unique()
                ->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('soldados');
    }
};
