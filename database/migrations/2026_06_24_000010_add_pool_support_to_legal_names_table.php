<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add standalone "pool" support to legal_names.
     *
     * Pool denominations are pre-generated (by AI) and reserved with the SE
     * ahead of any expedient, so they have no registration. registration_id is
     * made nullable, and a company_type column carries the régimen the pool name
     * is reserved under (the bot needs it to submit, normally 'srl').
     */
    public function up(): void
    {
        Schema::table('legal_names', function (Blueprint $table) {
            // Drop NOT NULL while keeping the FK so pool names need no registration.
            $table->ulid('registration_id')->nullable()->change();

            if (! Schema::hasColumn('legal_names', 'company_type')) {
                $table->string('company_type')
                    ->nullable()
                    ->after('name')
                    ->comment('Régimen used when the name has no registration (sa/srl/sapi)');
            }
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('legal_names', function (Blueprint $table) {
            if (Schema::hasColumn('legal_names', 'company_type')) {
                $table->dropColumn('company_type');
            }
        });
    }
};
