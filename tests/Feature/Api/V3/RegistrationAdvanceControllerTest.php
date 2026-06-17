<?php

namespace Tests\Feature\Api\V3;

use App\Enums\RegistrationStageEnum;
use App\Enums\RegistrationStatusEnum;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Feature tests for POST /api/v3/registrations/{code}/advance.
 *
 * Covers authentication, 404 handling, unprocessable scenarios,
 * and the successful advancement with audit trail.
 */
class RegistrationAdvanceControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $notary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notary = User::factory()->create();
    }

    // -------------------------------------------------------------------------
    // Authorization
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_401_when_not_authenticated(): void
    {
        Registration::factory()->create(['singapur_client_code' => '000001']);

        $this->postJson('/api/v3/registrations/000001/advance')
            ->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    // -------------------------------------------------------------------------
    // 404 scenarios
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_404_when_registration_does_not_exist(): void
    {
        $this->actingAs($this->notary, 'api')
            ->postJson('/api/v3/registrations/999999/advance')
            ->assertStatus(Response::HTTP_NOT_FOUND)
            ->assertJsonPath('error', 'Registration not found');
    }

    // -------------------------------------------------------------------------
    // 422 scenarios — cannot advance
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_422_when_the_registration_is_already_completed(): void
    {
        Registration::factory()->create([
            'singapur_client_code' => '000001',
            'stage'                => RegistrationStageEnum::COMPLETED,
            'status'               => RegistrationStatusEnum::COMPLETED,
        ]);

        $this->actingAs($this->notary, 'api')
            ->postJson('/api/v3/registrations/000001/advance')
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
            ->assertJsonStructure(['error']);
    }

    #[Test]
    public function it_returns_422_when_the_registration_is_on_hold(): void
    {
        Registration::factory()->create([
            'singapur_client_code' => '000001',
            'stage'                => RegistrationStageEnum::LEGAL_NAME,
            'status'               => RegistrationStatusEnum::ON_HOLD,
        ]);

        $this->actingAs($this->notary, 'api')
            ->postJson('/api/v3/registrations/000001/advance')
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Test]
    public function it_returns_422_when_the_registration_is_cancelled(): void
    {
        Registration::factory()->create([
            'singapur_client_code' => '000001',
            'stage'                => RegistrationStageEnum::DATA_RECEIVED,
            'status'               => RegistrationStatusEnum::CANCELLED,
        ]);

        $this->actingAs($this->notary, 'api')
            ->postJson('/api/v3/registrations/000001/advance')
            ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    // -------------------------------------------------------------------------
    // 200 — successful advance
    // -------------------------------------------------------------------------

    #[Test]
    public function it_advances_the_stage_and_returns_the_updated_registration(): void
    {
        Registration::factory()->create([
            'singapur_client_code' => '000001',
            'stage'                => RegistrationStageEnum::DATA_RECEIVED,
            'status'               => RegistrationStatusEnum::ACTIVE,
        ]);

        $response = $this->actingAs($this->notary, 'api')
            ->postJson('/api/v3/registrations/000001/advance');

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('data.singapur_client_code', '000001')
            ->assertJsonPath('data.stage', 'identity_validation')
            ->assertJsonPath('data.stage_progress', 2);
    }

    #[Test]
    public function it_persists_the_stage_transition_record(): void
    {
        Registration::factory()->create([
            'singapur_client_code' => '000001',
            'stage'                => RegistrationStageEnum::DATA_RECEIVED,
        ]);

        $this->actingAs($this->notary, 'api')
            ->postJson('/api/v3/registrations/000001/advance');

        $this->assertDatabaseHas('stage_transitions', [
            'from_stage'   => 'data_received',
            'to_stage'     => 'identity_validation',
            'performed_by' => $this->notary->id,
        ]);
    }

    #[Test]
    public function it_stores_the_optional_reason_in_the_transition(): void
    {
        Registration::factory()->create([
            'singapur_client_code' => '000001',
            'stage'                => RegistrationStageEnum::DATA_RECEIVED,
        ]);

        $this->actingAs($this->notary, 'api')
            ->postJson('/api/v3/registrations/000001/advance', [
                'reason' => 'Documentos completos y verificados.',
            ]);

        $this->assertDatabaseHas('stage_transitions', [
            'from_stage' => 'data_received',
            'reason'     => 'Documentos completos y verificados.',
        ]);
    }

    #[Test]
    public function it_sets_status_to_completed_when_advancing_from_the_last_stage(): void
    {
        Registration::factory()->create([
            'singapur_client_code' => '000001',
            'stage'                => RegistrationStageEnum::EFIRMA_APPOINTMENT,
            'status'               => RegistrationStatusEnum::ACTIVE,
        ]);

        $response = $this->actingAs($this->notary, 'api')
            ->postJson('/api/v3/registrations/000001/advance');

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('data.stage', 'completed')
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('registrations', [
            'singapur_client_code' => '000001',
            'stage'                => 'completed',
            'status'               => 'completed',
        ]);
    }

    #[Test]
    public function it_records_the_authenticated_user_as_the_actor(): void
    {
        Registration::factory()->create([
            'singapur_client_code' => '000001',
            'stage'                => RegistrationStageEnum::DATA_RECEIVED,
        ]);

        $this->actingAs($this->notary, 'api')
            ->postJson('/api/v3/registrations/000001/advance');

        $this->assertDatabaseHas('stage_transitions', [
            'performed_by' => $this->notary->id,
        ]);
    }
}
