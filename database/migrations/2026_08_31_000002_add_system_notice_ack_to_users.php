<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the user acknowledged the current system notice (the dashboard banner about the acta
 * AI-extraction change). Null = not yet seen/authorized → the banner shows. Once set, the banner
 * disappears for good and we keep the timestamp as a record that they gave the go-ahead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dateTime('system_notice_ack_at')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('system_notice_ack_at');
        });
    }
};
