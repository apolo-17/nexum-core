<?php

namespace Tests\Feature\Api\V3;

use App\Enums\LegalNameStatusEnum;
use App\Enums\RegistrationStageEnum;
use App\Enums\RegistrationStatusEnum;
use App\Enums\ShareholderRoleEnum;
use App\Models\LegalName;
use App\Models\Registration;
use App\Models\Shareholder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Feature tests for the V3 RegistrationController.
 *
 * All endpoints require JWT authentication. Tests use actingAs() since the
 * read-only endpoints do not require a real token (no JWT-specific operations).
 */
class RegistrationControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    // -------------------------------------------------------------------------
    // GET /api/v3/registrations — index
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_401_on_index_without_token(): void
    {
        $this->getJson('/api/v3/registrations')
            ->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function it_returns_paginated_registrations(): void
    {
        Registration::factory()->count(3)->create();

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v3/registrations');

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'singapur_client_code',
                        'stage',
                        'stage_label',
                        'stage_progress',
                        'stage_total',
                        'status',
                        'company_type',
                        'company_name',
                    ],
                ],
                'meta' => ['current_page', 'total', 'per_page'],
            ]);
    }

    #[Test]
    public function it_returns_an_empty_list_when_no_registrations_exist(): void
    {
        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v3/registrations');

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function it_respects_per_page_query_parameter(): void
    {
        Registration::factory()->count(10)->create();

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v3/registrations?per_page=3');

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.per_page', 3);
    }

    #[Test]
    public function it_caps_per_page_at_100(): void
    {
        Registration::factory()->count(5)->create();

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v3/registrations?per_page=999');

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('meta.per_page', 100);
    }

    #[Test]
    public function it_orders_registrations_by_most_recently_created(): void
    {
        $first  = Registration::factory()->create(['created_at' => now()->subDays(2)]);
        $second = Registration::factory()->create(['created_at' => now()->subDay()]);
        $third  = Registration::factory()->create(['created_at' => now()]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v3/registrations');

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertSame($third->id, $ids[0]);
        $this->assertSame($second->id, $ids[1]);
        $this->assertSame($first->id, $ids[2]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v3/registrations/{code} — show
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_401_on_show_without_token(): void
    {
        $this->getJson('/api/v3/registrations/000001')
            ->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function it_returns_a_single_registration_by_singapur_client_code(): void
    {
        $registration = Registration::factory()->create([
            'singapur_client_code' => '000001',
            'company_type'         => 'SA de CV',
            'stage'                => RegistrationStageEnum::DATA_RECEIVED,
            'status'               => RegistrationStatusEnum::ACTIVE,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v3/registrations/000001');

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('data.id', $registration->id)
            ->assertJsonPath('data.singapur_client_code', '000001')
            ->assertJsonPath('data.stage', 'data_received')
            ->assertJsonPath('data.stage_label', 'Datos recibidos')
            ->assertJsonPath('data.stage_progress', 1)
            ->assertJsonPath('data.stage_total', 8)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.company_type', 'SA de CV');
    }

    #[Test]
    public function it_returns_404_when_registration_code_does_not_exist(): void
    {
        $this->actingAs($this->user, 'api')
            ->getJson('/api/v3/registrations/999999')
            ->assertStatus(Response::HTTP_NOT_FOUND)
            ->assertJsonPath('error', 'Registration not found');
    }

    #[Test]
    public function it_includes_company_name_from_priority_1_legal_name(): void
    {
        $registration = Registration::factory()->create([
            'singapur_client_code' => '000002',
        ]);

        LegalName::factory()->create([
            'registration_id' => $registration->id,
            'name'            => 'NOVA CONSULTORÍA EMPRESARIAL',
            'priority'        => 1,
            'status'          => LegalNameStatusEnum::WAIT,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v3/registrations/000002');

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('data.company_name', 'NOVA CONSULTORÍA EMPRESARIAL');
    }

    #[Test]
    public function it_includes_nested_shareholders_in_the_response(): void
    {
        $registration = Registration::factory()->create([
            'singapur_client_code' => '000003',
        ]);

        Shareholder::factory()->create([
            'registration_id'         => $registration->id,
            'name'                    => '吴佳鑫',
            'nationality'             => 'china',
            'participation_percentage' => 50.00,
            'role'                    => ShareholderRoleEnum::LEGAL_REPRESENTATIVE,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v3/registrations/000003');

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonCount(1, 'data.shareholders')
            ->assertJsonPath('data.shareholders.0.name', '吴佳鑫')
            ->assertJsonPath('data.shareholders.0.role', 'legal_representative')
            ->assertJsonPath('data.shareholders.0.participation_percentage', 50);
    }

    #[Test]
    public function it_returns_correct_stage_progress_for_non_first_stage(): void
    {
        Registration::factory()->create([
            'singapur_client_code' => '000004',
            'stage'                => RegistrationStageEnum::INCORPORATION,
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v3/registrations/000004');

        $response->assertStatus(Response::HTTP_OK)
            ->assertJsonPath('data.stage', 'incorporation')
            ->assertJsonPath('data.stage_progress', 4)
            ->assertJsonPath('data.stage_total', 8);
    }
}
