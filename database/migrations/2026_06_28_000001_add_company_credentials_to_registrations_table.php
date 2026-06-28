<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds company-level credential columns to the registrations table.
 *
 * These store the incorporated company's own e.firma (FIEL) and RFC document for
 * safekeeping/download — independent from the e.firma SAT appointment flow
 * (efirma_* columns), which tracks the appointment outcome at stage 8.
 *
 * Files (.cer/.key and the RFC constancia) live on the default filesystem disk
 * (R2 in production). The password is stored with reversible encryption (Laravel
 * `encrypted` cast) so it can be retrieved for download — not hashed.
 */
return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table): void {
            $table->string('company_fiel_cer_path')
                ->nullable()
                ->after('efirma_password_hash')
                ->comment('Storage path (R2) for the company e.firma .cer file — safekeeping/download');

            $table->string('company_fiel_key_path')
                ->nullable()
                ->after('company_fiel_cer_path')
                ->comment('Storage path (R2) for the company e.firma .key file — safekeeping/download');

            $table->text('company_fiel_password')
                ->nullable()
                ->after('company_fiel_key_path')
                ->comment('Company e.firma password — reversibly encrypted (Crypt) so it can be retrieved');

            $table->string('company_rfc_path')
                ->nullable()
                ->after('company_fiel_password')
                ->comment('Storage path (R2) for the company RFC document (Constancia de Situación Fiscal)');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table): void {
            $table->dropColumn([
                'company_fiel_cer_path',
                'company_fiel_key_path',
                'company_fiel_password',
                'company_rfc_path',
            ]);
        });
    }
};
