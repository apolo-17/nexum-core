<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reworks the appointment lifecycle for the "form manually → bot reviews" model.
 *
 * The status now uses AppointmentStatusEnum (pending_forming, formed, scheduled,
 * rejected, no_show) instead of the shared EfirmaAppointmentStatusEnum. Adds formed_at
 * (when the team formed it at the SAT) and last_review_at (when the bot last checked).
 */
return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dateTime('formed_at')->nullable()->after('scheduled_at')
                ->comment('When the team formed the appointment at the SAT (virtual queue)');
            $table->dateTime('last_review_at')->nullable()->after('formed_at')
                ->comment('When the bot last checked the SAT status');
        });

        // Remap any existing statuses to the new vocabulary.
        DB::table('appointments')->where('status', 'pending_scheduling')->update(['status' => 'pending_forming']);
        DB::table('appointments')->where('status', 'attended_approved')->update(['status' => 'scheduled']);
        DB::table('appointments')->where('status', 'attended_rejected')->update(['status' => 'rejected']);
        // 'scheduled' and 'no_show' keep their values.

        Schema::table('appointments', function (Blueprint $table): void {
            $table->string('status')->default('pending_forming')->comment('AppointmentStatusEnum')->change();
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->string('status')->default('pending_scheduling')->change();
            $table->dropColumn(['formed_at', 'last_review_at']);
        });
    }
};
