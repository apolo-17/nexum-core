<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Removes bank_account from the active stage flow and maps existing records to tax_address.
 *
 * Context: The stage pipeline was refactored to:
 * - Remove apertura de cuenta bancaria (bank_account) — no longer a tracked stage.
 * - Add firma de socios (partner_signature) between legal_name and incorporation.
 * - Add domicilio fiscal (tax_address) between incorporation and sat_registration.
 *
 * Records that were at bank_account are mapped to tax_address (the new stage that
 * occupies the same position in the workflow after incorporation).
 *
 * Coordinate with the project 360 SOT before running on shared environments.
 */
return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        // Migrate any registrations stuck at the removed bank_account stage.
        // They advance to tax_address, the new stage after incorporation.
        DB::table('registrations')
            ->where('stage', 'bank_account')
            ->update(['stage' => 'tax_address']);

        // Also fix any stage_transitions that recorded bank_account as from/to stage.
        DB::table('stage_transitions')
            ->where('from_stage', 'bank_account')
            ->update(['from_stage' => 'tax_address']);

        DB::table('stage_transitions')
            ->where('to_stage', 'bank_account')
            ->update(['to_stage' => 'tax_address']);
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        DB::table('registrations')
            ->where('stage', 'tax_address')
            ->update(['stage' => 'bank_account']);

        DB::table('stage_transitions')
            ->where('from_stage', 'tax_address')
            ->update(['from_stage' => 'bank_account']);

        DB::table('stage_transitions')
            ->where('to_stage', 'tax_address')
            ->update(['to_stage' => 'bank_account']);
    }
};
