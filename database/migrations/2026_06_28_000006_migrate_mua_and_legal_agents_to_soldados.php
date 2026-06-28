<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Data migration: consolidate mua_accounts and legal_agents into soldados.
 *
 * Strategy:
 *  - IDs are PRESERVED (soldado.id = mua_account.id / legal_agent.id) so the
 *    legal_names.mua_account_id values backfill straight into soldado_id and the
 *    legacy pivot remaps cleanly.
 *  - The same real person is DEDUPED by RFC: a legal_agent whose RFC already exists
 *    as a MUA soldado is linked to it (its capability flag is set) instead of being
 *    inserted again.
 *  - Source tables (mua_accounts, legal_agents, *_credentials, pivot) are left intact
 *    for one release; they are no longer read by the application.
 *
 * Idempotent: re-running skips rows that already exist. Soft-deleted source rows are
 * skipped. down() is intentionally a no-op — this is a one-way consolidation.
 */
return new class extends Migration
{
    /**
     * Run the consolidation.
     */
    public function up(): void
    {
        $now = Carbon::now();

        // rfc → soldado_id, used to dedupe legal_agents against MUA soldados.
        $rfcToSoldadoId = [];

        // 1. mua_accounts → soldados (available_for_mua = true), preserving id.
        foreach (DB::table('mua_accounts')->whereNull('deleted_at')->get() as $mua) {
            if (! DB::table('soldados')->where('id', $mua->id)->exists()) {
                DB::table('soldados')->insert([
                    'id' => $mua->id,
                    'name' => $mua->name,
                    'email' => $this->uniqueEmail($mua->email),
                    'rfc' => $mua->rfc,
                    'available_for_mua' => true,
                    'is_active' => (bool) $mua->is_active,
                    'active_submissions' => $mua->active_submissions ?? 0,
                    'created_at' => $mua->created_at ?? $now,
                    'updated_at' => $mua->updated_at ?? $now,
                ]);
            } else {
                DB::table('soldados')->where('id', $mua->id)->update(['available_for_mua' => true]);
            }

            if ($mua->rfc !== null) {
                $rfcToSoldadoId[$mua->rfc] = $mua->id;
            }
        }

        // 2. mua_credentials → soldado_credentials (soldado_id = mua_account_id).
        foreach (DB::table('mua_credentials')->get() as $cred) {
            if (! DB::table('soldados')->where('id', $cred->mua_account_id)->exists()) {
                continue;
            }

            $exists = DB::table('soldado_credentials')
                ->where('soldado_id', $cred->mua_account_id)
                ->where('type', $cred->type)
                ->exists();

            if (! $exists) {
                DB::table('soldado_credentials')->insert([
                    'id' => $cred->id,
                    'soldado_id' => $cred->mua_account_id,
                    'type' => $cred->type,
                    'credential' => $cred->credential,
                    'created_at' => $cred->created_at ?? $now,
                    'updated_at' => $cred->updated_at ?? $now,
                ]);
            }
        }

        // 3. legal_agents → soldados (dedupe by RFC), tracking id remap + role.
        $agentToSoldadoId = [];
        $agentRole = [];

        foreach (DB::table('legal_agents')->whereNull('deleted_at')->get() as $agent) {
            $flag = $agent->type === 'commissary'
                ? 'available_as_commissary'
                : 'available_as_legal_representative';

            $agentRole[$agent->id] = $agent->type;

            // Same person already migrated from MUA → just set the capability flag.
            if ($agent->rfc !== null && isset($rfcToSoldadoId[$agent->rfc])) {
                $soldadoId = $rfcToSoldadoId[$agent->rfc];
                DB::table('soldados')->where('id', $soldadoId)->update([$flag => true]);
                $agentToSoldadoId[$agent->id] = $soldadoId;

                continue;
            }

            if (! DB::table('soldados')->where('id', $agent->id)->exists()) {
                DB::table('soldados')->insert([
                    'id' => $agent->id,
                    'name' => $agent->name,
                    'email' => $this->uniqueEmail($agent->email),
                    'phone' => $agent->phone,
                    'rfc' => $this->uniqueRfc($agent->rfc),
                    'curp' => $agent->curp,
                    'birthdate' => $agent->birthdate,
                    'birthplace' => $agent->birthplace,
                    'address' => $agent->address,
                    'notes' => $agent->notes,
                    'available_for_mua' => false,
                    'available_as_legal_representative' => $agent->type === 'legal_representative',
                    'available_as_commissary' => $agent->type === 'commissary',
                    'is_active' => (bool) $agent->is_active,
                    'created_at' => $agent->created_at ?? $now,
                    'updated_at' => $agent->updated_at ?? $now,
                ]);
            } else {
                DB::table('soldados')->where('id', $agent->id)->update([$flag => true]);
            }

            if ($agent->rfc !== null) {
                $rfcToSoldadoId[$agent->rfc] = $agent->id;
            }

            $agentToSoldadoId[$agent->id] = $agent->id;
        }

        // 4. Backfill legal_names.soldado_id from mua_account_id (ids preserved).
        // Restrict to MUA accounts that actually migrated to satisfy the FK.
        DB::table('legal_names')
            ->whereNotNull('mua_account_id')
            ->whereIn('mua_account_id', DB::table('soldados')->pluck('id')->all())
            ->update(['soldado_id' => DB::raw('mua_account_id')]);

        // 5. legal_agent_registration → registration_soldado (with role).
        foreach (DB::table('legal_agent_registration')->get() as $pivot) {
            $soldadoId = $agentToSoldadoId[$pivot->legal_agent_id] ?? null;

            if ($soldadoId === null) {
                continue;
            }

            $exists = DB::table('registration_soldado')
                ->where('soldado_id', $soldadoId)
                ->where('registration_id', $pivot->registration_id)
                ->exists();

            if (! $exists) {
                DB::table('registration_soldado')->insert([
                    'soldado_id' => $soldadoId,
                    'registration_id' => $pivot->registration_id,
                    'role' => $agentRole[$pivot->legal_agent_id] ?? null,
                    'participation_percentage' => $pivot->participation_percentage,
                    'created_at' => $pivot->created_at ?? $now,
                    'updated_at' => $pivot->updated_at ?? $now,
                ]);
            }
        }
    }

    /**
     * Reverse the migration — intentionally a no-op (one-way consolidation).
     */
    public function down(): void
    {
        // Source tables are preserved; the consolidation is not reversed automatically.
    }

    /**
     * Return the email if free in soldados, otherwise null (avoids unique collision).
     *
     * @param  string|null  $email  Candidate email from the source row.
     */
    private function uniqueEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        return DB::table('soldados')->where('email', $email)->exists() ? null : $email;
    }

    /**
     * Return the RFC if free in soldados, otherwise null (handles placeholder collisions).
     *
     * @param  string|null  $rfc  Candidate RFC from the source row.
     */
    private function uniqueRfc(?string $rfc): ?string
    {
        if ($rfc === null) {
            return null;
        }

        return DB::table('soldados')->where('rfc', $rfc)->exists() ? null : $rfc;
    }
};
