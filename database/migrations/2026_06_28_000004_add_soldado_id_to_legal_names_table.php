<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds soldado_id to legal_names as the new FIEL owner reference.
 *
 * Replaces mua_account_id going forward. The old column is kept for one release
 * (it still carries a FK to mua_accounts) to avoid risky FK drops on SQLite; a
 * later cleanup migration removes it once the consolidation is proven in prod.
 * The data migration backfills soldado_id from mua_account_id (IDs are preserved
 * across the MuaAccount → Soldado migration).
 */
return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        Schema::table('legal_names', function (Blueprint $table): void {
            $table->foreignUlid('soldado_id')
                ->nullable()
                ->after('mua_account_id')
                ->constrained('soldados')
                ->nullOnDelete()
                ->comment('Soldado whose FIEL is assigned to submit this denomination');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('legal_names', function (Blueprint $table): void {
            $table->dropForeign(['soldado_id']);
            $table->dropColumn('soldado_id');
        });
    }
};
