<?php

namespace Tests\Feature\Api\V3;

use App\Enums\LegalNameStatusEnum;
use App\Models\LegalName;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for the denomination pool API consumed by the China/Singapur front.
 *
 * Covers listing only available approved pool names and claiming one for a
 * registration (including the double-claim and unknown-registration guards).
 */
class DenominationPoolControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(), 'api');
    }

    #[Test]
    public function available_lists_only_approved_pool_names(): void
    {
        $approvedPool = LegalName::create([
            'registration_id' => null,
            'name' => 'ALFA CONSULTORES',
            'company_type' => 'srl',
            'priority' => 1,
            'status' => LegalNameStatusEnum::APPROVED,
            'clave_unica_denominacion' => 'A1B2C3',
        ]);

        // Draft pool name — not yet approved, must be excluded.
        LegalName::create([
            'registration_id' => null,
            'name' => 'BETA DRAFT',
            'company_type' => 'srl',
            'priority' => 1,
            'status' => LegalNameStatusEnum::DRAFT,
        ]);

        // Approved but bound to a registration — must be excluded.
        $registration = Registration::factory()->create();
        LegalName::create([
            'registration_id' => $registration->id,
            'name' => 'GAMMA EXPEDIENTE',
            'priority' => 1,
            'status' => LegalNameStatusEnum::APPROVED,
        ]);

        $response = $this->getJson('/api/v3/denominations/available');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $approvedPool->id)
            ->assertJsonPath('data.0.folio', 'A1B2C3');
    }

    #[Test]
    public function claim_assigns_an_available_name_to_a_registration(): void
    {
        $registration = Registration::factory()->create(['singapur_client_code' => '000123']);

        $name = LegalName::create([
            'registration_id' => null,
            'name' => 'ALFA CONSULTORES',
            'company_type' => 'srl',
            'priority' => 1,
            'status' => LegalNameStatusEnum::APPROVED,
        ]);

        $response = $this->postJson("/api/v3/denominations/{$name->id}/claim", [
            'registration_code' => '000123',
        ]);

        $response->assertOk();
        $this->assertSame($registration->id, $name->fresh()->registration_id);
    }

    #[Test]
    public function claim_is_rejected_when_the_name_is_already_taken(): void
    {
        $registration = Registration::factory()->create(['singapur_client_code' => '000123']);

        $name = LegalName::create([
            'registration_id' => $registration->id,
            'name' => 'ALREADY TAKEN',
            'company_type' => 'srl',
            'priority' => 1,
            'status' => LegalNameStatusEnum::APPROVED,
        ]);

        $response = $this->postJson("/api/v3/denominations/{$name->id}/claim", [
            'registration_code' => '000123',
        ]);

        $response->assertStatus(409);
    }

    #[Test]
    public function claim_returns_404_for_an_unknown_registration(): void
    {
        $name = LegalName::create([
            'registration_id' => null,
            'name' => 'ALFA CONSULTORES',
            'company_type' => 'srl',
            'priority' => 1,
            'status' => LegalNameStatusEnum::APPROVED,
        ]);

        $response = $this->postJson("/api/v3/denominations/{$name->id}/claim", [
            'registration_code' => 'NON-EXISTENT',
        ]);

        $response->assertStatus(404);
    }
}
