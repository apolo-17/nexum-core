<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "FIEL vigente hasta" — the expiry date of the soldado's own e.firma (FIEL).
 *
 * A soldado can only be admitted to an e.firma (FIEL) appointment if their personal FIEL is
 * still valid, so we keep this date (captured manually) to warn when picking who attends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('soldados', function (Blueprint $table): void {
            $table->date('fiel_valid_until')->nullable()->after('curp')
                ->comment('Expiry date of the soldado own e.firma (FIEL). Advisory for e.firma appointments.');
        });
    }

    public function down(): void
    {
        Schema::table('soldados', function (Blueprint $table): void {
            $table->dropColumn('fiel_valid_until');
        });
    }
};
