<?php

namespace Tests\Feature\Api\V3;

use App\Models\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for the manual intake read endpoint.
 */
class IntakeControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.intake.token' => 'intake-secret']);
    }

    #[Test]
    public function it_requires_the_intake_token(): void
    {
        $registration = Registration::factory()->create(['singapur_client_code' => '000123']);

        $this->getJson("/api/v3/intake/{$registration->singapur_client_code}")
            ->assertUnauthorized();

        $this->withHeader('X-Intake-Token', 'wrong')
            ->getJson("/api/v3/intake/{$registration->singapur_client_code}")
            ->assertUnauthorized();
    }

    #[Test]
    public function it_returns_the_expediente_state_by_client_code(): void
    {
        $registration = Registration::factory()->create([
            'singapur_client_code' => '000123',
            'company_type' => 'SRL de CV',
        ]);

        $this->withHeader('X-Intake-Token', 'intake-secret')
            ->getJson("/api/v3/intake/{$registration->singapur_client_code}")
            ->assertOk()
            ->assertJsonPath('data.client_code', '000123')
            ->assertJsonPath('data.company.company_type', 'SRL de CV')
            ->assertJsonStructure(['data' => [
                'id', 'stage', 'status', 'company', 'fiscal_address',
                'shareholders', 'legal_names', 'documents', 'documents_missing',
            ]]);
    }

    #[Test]
    public function it_resolves_by_ulid_too(): void
    {
        $registration = Registration::factory()->create(['singapur_client_code' => '000123']);

        $this->withHeader('X-Intake-Token', 'intake-secret')
            ->getJson("/api/v3/intake/{$registration->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $registration->id);
    }

    #[Test]
    public function it_404s_an_unknown_expediente(): void
    {
        $this->withHeader('X-Intake-Token', 'intake-secret')
            ->getJson('/api/v3/intake/999999')
            ->assertNotFound();
    }
}
