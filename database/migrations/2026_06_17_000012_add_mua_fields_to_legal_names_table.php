<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add MUA tracking fields to the legal_names table.
     *
     * - mua_account_id: which soldado's FIEL is processing this denomination.
     * - rejection_reason: SE rejection category returned after review.
     * - mua_available: cached result of the MUA availability pre-check.
     */
    public function up(): void
    {
        if (Schema::hasColumn('legal_names', 'mua_account_id')) {
            return;
        }

        Schema::table('legal_names', function (Blueprint $table) {
            $table->foreignUlid('mua_account_id')
                ->nullable()
                ->constrained('mua_accounts')
                ->nullOnDelete()
                ->after('submitted_at')
                ->comment('FIEL account (soldado) assigned to submit this denomination');

            $table->string('rejection_reason')->nullable()
                ->after('mua_account_id')
                ->comment('Rejection category returned by the SE');

            $table->boolean('mua_available')->nullable()
                ->after('rejection_reason')
                ->comment('Cached result of the pre-submission MUA availability check');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('legal_names', function (Blueprint $table) {
            $table->dropForeign(['mua_account_id']);
            $table->dropColumn(['mua_account_id', 'rejection_reason', 'mua_available']);
        });
    }
};
