<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the company's e.firma requerimiento (.req) alongside the rest of the FIEL,
 * so each expedient keeps the complete e.firma documentation (cer + key + req + password).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table): void {
            $table->string('company_fiel_req_path')->nullable()->after('company_rfc_path');
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table): void {
            $table->dropColumn('company_fiel_req_path');
        });
    }
};
