<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the legacy e.firma columns on registrations. That flow (efirma_status +
 * efirma_appointment_at + efirma_key_path/efirma_cer_path/efirma_password_hash, driven by
 * RequestEfirmaAppointmentAction / ConfirmEfirmaOutcomeAction) was replaced by the real
 * SAT flow: a FIEL Appointment (cita) + the soldado uploading the company FIEL to
 * company_fiel_cer_path / company_fiel_key_path / company_fiel_password. The old columns
 * were dead and are removed for clarity.
 */
return new class extends Migration
{
    private const LEGACY_COLUMNS = [
        'efirma_appointment_at',
        'efirma_status',
        'efirma_key_path',
        'efirma_cer_path',
        'efirma_password_hash',
    ];

    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table): void {
            foreach (self::LEGACY_COLUMNS as $column) {
                if (Schema::hasColumn('registrations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table): void {
            $table->timestamp('efirma_appointment_at')->nullable()->after('rfc');
            $table->string('efirma_status')->nullable()->after('efirma_appointment_at');
            $table->string('efirma_key_path')->nullable()->after('efirma_status');
            $table->string('efirma_cer_path')->nullable()->after('efirma_key_path');
            $table->string('efirma_password_hash')->nullable()->after('efirma_cer_path');
        });
    }
};
