<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds corporate structure fields to the registrations table.
 *
 * These fields are required to generate the acta constitutiva. They arrive from
 * the Singapur relay in the webhook payload (extended contract) or can be filled
 * manually by the notary team through the Filament dashboard.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table): void {
            $table->text('company_object')
                ->nullable()
                ->after('company_type')
                ->comment('Corporate purpose / objeto social — required for the acta constitutiva.');

            $table->decimal('capital_social', 12, 2)
                ->nullable()
                ->after('company_object')
                ->comment('Total share capital in MXN. Defaults to minimum legal (50,000 for SA de CV).');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table): void {
            $table->dropColumn(['company_object', 'capital_social']);
        });
    }
};
