<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the mua_accounts table.
     *
     * Each row represents a "soldado" — a person whose FIEL (e.firma) credentials
     * are registered with the Secretaría de Economía and can be used to submit
     * company denomination reservations to the MUA portal.
     */
    public function up(): void
    {
        if (Schema::hasTable('mua_accounts')) {
            return;
        }

        Schema::create('mua_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->string('name')->comment('Display name of the FIEL holder (soldado)');
            $table->string('rfc', 13)->unique()->comment('RFC of the FIEL holder');
            $table->boolean('is_active')->default(true)->comment('Whether this account is available for use');
            $table->unsignedInteger('active_submissions')->default(0)
                ->comment('Count of denominations currently assigned to this account in PROCESS state');

            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mua_accounts');
    }
};
