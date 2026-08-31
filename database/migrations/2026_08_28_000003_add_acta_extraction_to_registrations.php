<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the AI extraction of a company's protocolized deed: the parsed parties (Chinese
 * shareholders and Mexican fiscal attorneys) plus the RFC reconciliation against our soldados.
 * Read by the expediente to show who was recovered from the acta and who is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table): void {
            $table->json('acta_extraction')->nullable()->after('company_object');
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table): void {
            $table->dropColumn('acta_extraction');
        });
    }
};
