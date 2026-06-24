<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add email column to the mua_accounts table.
     *
     * Nullable to avoid breaking existing rows already in production.
     * Positioned after RFC to reflect display priority in the form.
     */
    public function up(): void
    {
        Schema::table('mua_accounts', function (Blueprint $table) {
            $table->string('email')->nullable()->after('rfc');
        });
    }

    /**
     * Remove the email column.
     */
    public function down(): void
    {
        Schema::table('mua_accounts', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
};
