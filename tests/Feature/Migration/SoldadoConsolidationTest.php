<?php

namespace Tests\Feature\Migration;

use App\Models\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Verifies the mua_accounts/legal_agents → soldados consolidation data migration.
 *
 * The migration ran against empty tables during RefreshDatabase; here we seed the
 * legacy tables with realistic data and re-run up() (it is idempotent) to exercise
 * the real consolidation path: id preservation, RFC dedupe, credential copy, the
 * legal_names backfill and the pivot remap with role.
 */
class SoldadoConsolidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run the consolidation migration's up() against the current (seeded) data.
     */
    private function runConsolidation(): void
    {
        $migration = require database_path(
            'migrations/2026_06_28_000006_migrate_mua_and_legal_agents_to_soldados.php'
        );

        $migration->up();
    }

    #[Test]
    public function it_migrates_a_mua_account_with_credentials_and_backfills_legal_names(): void
    {
        $muaId = (string) Str::ulid();

        DB::table('mua_accounts')->insert([
            'id' => $muaId,
            'name' => 'Apolo FIEL',
            'email' => 'apolo@notaria.mx',
            'rfc' => 'AOMA940814N35',
            'is_active' => true,
            'active_submissions' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('mua_credentials')->insert([
            'id' => (string) Str::ulid(),
            'mua_account_id' => $muaId,
            'type' => 'certificate',
            'credential' => 'enc-cert',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $registration = Registration::factory()->create();

        DB::table('legal_names')->insert([
            'id' => (string) Str::ulid(),
            'registration_id' => $registration->id,
            'name' => 'DELTA SERVICIOS',
            'priority' => 1,
            'status' => 'pending',
            'mua_account_id' => $muaId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runConsolidation();

        // Soldado created with the same id and MUA capability.
        $this->assertDatabaseHas('soldados', [
            'id' => $muaId,
            'rfc' => 'AOMA940814N35',
            'available_for_mua' => true,
        ]);

        // Credential copied under the new owner.
        $this->assertDatabaseHas('soldado_credentials', [
            'soldado_id' => $muaId,
            'type' => 'certificate',
            'credential' => 'enc-cert',
        ]);

        // legal_names.soldado_id backfilled from mua_account_id.
        $this->assertDatabaseHas('legal_names', [
            'name' => 'DELTA SERVICIOS',
            'soldado_id' => $muaId,
        ]);
    }

    #[Test]
    public function it_dedupes_a_legal_agent_sharing_an_rfc_with_a_mua_account(): void
    {
        $muaId = (string) Str::ulid();
        $agentId = (string) Str::ulid();

        DB::table('mua_accounts')->insert([
            'id' => $muaId,
            'name' => 'Persona Doble',
            'email' => 'doble@notaria.mx',
            'rfc' => 'XEXX010101000',
            'is_active' => true,
            'active_submissions' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Same person (same RFC) also exists as a legal representative.
        DB::table('legal_agents')->insert([
            'id' => $agentId,
            'type' => 'legal_representative',
            'name' => 'Persona Doble',
            'rfc' => 'XEXX010101000',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $registration = Registration::factory()->create();

        DB::table('legal_agent_registration')->insert([
            'legal_agent_id' => $agentId,
            'registration_id' => $registration->id,
            'participation_percentage' => 60.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runConsolidation();

        // Only ONE soldado for the shared RFC — deduped onto the MUA soldado.
        $this->assertSame(1, DB::table('soldados')->where('rfc', 'XEXX010101000')->count());

        // That soldado carries both capabilities.
        $this->assertDatabaseHas('soldados', [
            'id' => $muaId,
            'available_for_mua' => true,
            'available_as_legal_representative' => true,
        ]);

        // The acta pivot points at the deduped soldado, with the role preserved.
        $this->assertDatabaseHas('registration_soldado', [
            'soldado_id' => $muaId,
            'registration_id' => $registration->id,
            'role' => 'legal_representative',
            'participation_percentage' => 60.00,
        ]);
    }
}
