<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reason a SAT appointment was rejected. Captured from the soldado (or an admin) when they
 * mark a cita as rejected, so we know WHY the SAT turned it down (wrong document, missing power,
 * FIEL not active, ...) and can act — instead of only seeing that it failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->text('rejection_reason')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn('rejection_reason');
        });
    }
};
