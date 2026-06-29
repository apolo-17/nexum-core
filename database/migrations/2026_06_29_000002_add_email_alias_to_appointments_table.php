<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds email_alias to appointments — the pool address assigned to this appointment so
 * the SAT bot can receive the token. Assigned by /sat-bot/pending, released on callback.
 */
return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->string('email_alias')->nullable()->after('office')
                ->comment('Pool address assigned to receive the SAT token');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn('email_alias');
        });
    }
};
