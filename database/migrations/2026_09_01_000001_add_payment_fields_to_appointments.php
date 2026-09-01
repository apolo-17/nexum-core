<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment tracking for a SAT appointment: what was paid (subtotal), when, and by whom.
 *
 * "Paid" is a stored fact (paid_at + amount). "Pending payment" is DERIVED (see Appointment::
 * scopePayable): the cita is only payable once its date has passed AND we already hold its result
 * (the RFC for an RFC cita, or the e.firma for a FIEL cita) — a rejected/no-show cita is never paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->decimal('payment_amount', 10, 2)->nullable()->after('rejection_reason');
            $table->dateTime('paid_at')->nullable()->after('payment_amount');
            $table->string('paid_by')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropColumn(['payment_amount', 'paid_at', 'paid_by']);
        });
    }
};
