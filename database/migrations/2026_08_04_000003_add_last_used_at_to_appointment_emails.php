<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track when a pool address was last used to form. The SAT keeps an address "in use" for
 * about a day after the cita, so an address must not be reused until 24h after its cita
 * passed — and, when a forming attempt fails, it is still burned at the SAT and can't be
 * reused for 24h either. last_used_at drives that cooldown in AppointmentEmail::claimFor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_emails', function (Blueprint $table): void {
            $table->timestamp('last_used_at')->nullable()->after('is_free')
                ->comment('Last time this address was assigned to a forming; drives the 24h SAT cooldown.');
        });
    }

    public function down(): void
    {
        Schema::table('appointment_emails', function (Blueprint $table): void {
            $table->dropColumn('last_used_at');
        });
    }
};
