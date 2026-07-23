<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the team pick which SAT office the bot should use to form an appointment.
 *
 * Nullable on purpose: when it is null the bot falls back to its own ordered list of
 * CDMX offices (SAT_MODULES), so appointments still work without anyone choosing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->unsignedInteger('preferred_module')->nullable()->after('office')
                ->comment('SatModule.sat_id the bot should form at; null = bot decides');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn('preferred_module');
        });
    }
};
