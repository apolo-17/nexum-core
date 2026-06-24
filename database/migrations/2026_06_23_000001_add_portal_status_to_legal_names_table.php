<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add portal_status column to legal_names.
     *
     * Stores the raw status label returned by the SE portal
     * (e.g. "Autorizada", "Con aviso de uso", "Desistida", "Rechazada por dictamen").
     * Kept separate from the internal status enum so the notary team can see
     * the exact SE wording without affecting business logic.
     */
    public function up(): void
    {
        Schema::table('legal_names', function (Blueprint $table) {
            $table->string('portal_status')
                ->nullable()
                ->after('rejection_reason')
                ->comment('Raw SE portal status label (Autorizada, Rechazada por dictamen, etc.)');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('legal_names', function (Blueprint $table) {
            $table->dropColumn('portal_status');
        });
    }
};
