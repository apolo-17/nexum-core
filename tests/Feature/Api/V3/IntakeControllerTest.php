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

    #[Test]
    public function complete_requires_the_token(): void
    {
        $registration = Registration::factory()->create(['singapur_client_code' => '000123']);

        $this->postJson("/api/v3/intake/{$registration->singapur_client_code}/complete", [])
            ->assertUnauthorized();
    }

    #[Test]
    public function complete_updates_fields_upserts_shareholders_and_stores_documents(): void
    {
        \Illuminate\Support\Facades\Storage::fake(config('filesystems.default'));

        $registration = Registration::factory()->create(['singapur_client_code' => '000123']);
        // Un socio pre-cargado con % placeholder, para probar que se actualiza y no duplica.
        $registration->shareholders()->create(['name' => 'WENJING LIU', 'nationality' => 'China', 'role' => 'shareholder', 'participation_percentage' => 60]);

        $payload = [
            'registration' => [
                'company_object' => 'Objeto social de prueba.',
                'capital_social' => 10000,
                'fiscal_street' => 'Cerrada José María Vigil',
                'fiscal_ext_number' => '2',
                'fiscal_neighborhood' => 'Escandón I Sección',
                'fiscal_municipality' => 'Miguel Hidalgo',
                'fiscal_state' => 'Ciudad de México',
                'fiscal_postal_code' => '11800',
            ],
            'shareholders' => [
                ['name' => 'WENJING LIU', 'participation_percentage' => 1, 'passport_number' => 'EC3076959'],
                ['name' => 'LINGLING YAO', 'nationality' => 'China', 'participation_percentage' => 99, 'role' => 'legal_representative'],
            ],
            'documents' => [
                ['type' => 'acta_signed', 'name' => 'ACTA.pdf', 'content_base64' => base64_encode('%PDF-1.4 acta')],
            ],
        ];

        $response = $this->withHeader('X-Intake-Token', 'intake-secret')
            ->postJson("/api/v3/intake/{$registration->singapur_client_code}/complete", $payload)
            ->assertOk();

        // Campos aplicados.
        $registration->refresh();
        $this->assertSame('Objeto social de prueba.', $registration->company_object);
        $this->assertSame('Miguel Hidalgo', $registration->fiscal_municipality);

        // Socios: WENJING actualizado (no duplicado), LINGLING creado.
        $this->assertSame(2, $registration->shareholders()->count());
        $wenjing = $registration->shareholders()->where('name', 'WENJING LIU')->first();
        $this->assertSame('1.00', (string) $wenjing->participation_percentage);
        $this->assertSame('EC3076959', $wenjing->passport_number);
        $lingling = $registration->shareholders()->where('name', 'LINGLING YAO')->first();
        $this->assertSame('99.00', (string) $lingling->participation_percentage);

        // Documento guardado con su tipo y verificado.
        $doc = $registration->documents()->where('name', 'ACTA.pdf')->first();
        $this->assertNotNull($doc);
        $this->assertNotNull($doc->storage_path);
        $this->assertNotNull($doc->verified_at);
        \Illuminate\Support\Facades\Storage::assertExists($doc->storage_path);

        $response->assertJsonPath('applied.documents.0.action', 'stored');
    }

    #[Test]
    public function complete_replace_mode_swaps_shareholders_and_fixes_denomination(): void
    {
        $registration = Registration::factory()->create(['singapur_client_code' => '000200']);
        // Placeholder socios seeded with Chinese-character names, to be replaced wholesale.
        $registration->shareholders()->create(['name' => '童钟玲', 'nationality' => 'China', 'role' => 'shareholder', 'participation_percentage' => 90]);
        $registration->shareholders()->create(['name' => '黄香珠', 'nationality' => 'China', 'role' => 'shareholder', 'participation_percentage' => 10]);
        // A denomination in a lingering `wait` status with no CUD.
        $legalName = $registration->legalNames()->create([
            'name' => 'YUNMALL MÉXICO', 'priority' => 1, 'status' => 'wait', 'company_type' => 'SRL de CV',
        ]);

        $payload = [
            'shareholders_mode' => 'replace',
            'denomination' => [
                'status' => 'approved',
                'clave_unica_denominacion' => 'A202602271840258356',
                'authorization_timestamp' => '2026-02-27T18:40:25',
            ],
            'shareholders' => [
                ['name' => 'ZHONGLING TONG', 'nationality' => 'China', 'passport_number' => 'ER6916027', 'participation_percentage' => 90, 'role' => 'legal_representative'],
                ['name' => 'XIANGZHU HUANG', 'nationality' => 'China', 'passport_number' => 'E73303194', 'participation_percentage' => 10, 'role' => 'shareholder'],
            ],
        ];

        $this->withHeader('X-Intake-Token', 'intake-secret')
            ->postJson('/api/v3/intake/000200/complete', $payload)
            ->assertOk()
            ->assertJsonPath('applied.shareholders.0.action', 'replaced');

        // Exactly the two romanized socios remain — the Chinese-named placeholders are gone.
        $names = $registration->shareholders()->pluck('name')->sort()->values()->all();
        $this->assertSame(['XIANGZHU HUANG', 'ZHONGLING TONG'], $names);
        $this->assertSame('ER6916027', $registration->shareholders()->where('name', 'ZHONGLING TONG')->first()->passport_number);

        // Denomination corrected: approved + CUD filled.
        $legalName->refresh();
        $this->assertSame('approved', $legalName->getRawOriginal('status'));
        $this->assertSame('A202602271840258356', $legalName->clave_unica_denominacion);
    }

    #[Test]
    public function complete_advances_the_pipeline_stage_forward_only(): void
    {
        $registration = Registration::factory()->create([
            'singapur_client_code' => '000300',
            'stage' => 'data_received',
            'status' => 'active',
        ]);

        // Advance a constituted expediente to "Domicilio fiscal".
        $this->withHeader('X-Intake-Token', 'intake-secret')
            ->postJson('/api/v3/intake/000300/complete', ['advance_to_stage' => 'tax_address'])
            ->assertOk()
            ->assertJsonPath('applied.stage.to', 'tax_address');

        $registration->refresh();
        $this->assertSame('tax_address', $registration->getRawOriginal('stage'));
        // Each hop recorded as an immutable system transition (performed_by null).
        $this->assertSame(6, $registration->stageTransitions()->count());
        $this->assertNull($registration->stageTransitions()->latest('id')->first()->performed_by);

        // Re-running with an earlier target never reverses.
        $this->withHeader('X-Intake-Token', 'intake-secret')
            ->postJson('/api/v3/intake/000300/complete', ['advance_to_stage' => 'legal_name'])
            ->assertOk();
        $this->assertSame('tax_address', $registration->refresh()->getRawOriginal('stage'));
    }

    #[Test]
    public function complete_can_reset_an_intake_advance(): void
    {
        $registration = Registration::factory()->create([
            'singapur_client_code' => '000301', 'stage' => 'data_received', 'status' => 'active',
        ]);

        // Advance by mistake…
        $this->withHeader('X-Intake-Token', 'intake-secret')
            ->postJson('/api/v3/intake/000301/complete', ['advance_to_stage' => 'tax_address'])->assertOk();
        $this->assertSame(6, $registration->refresh()->stageTransitions()->count());

        // …then revert: stage falls back and the intake transitions are removed.
        $this->withHeader('X-Intake-Token', 'intake-secret')
            ->postJson('/api/v3/intake/000301/complete', ['reset_stage_to' => 'data_received'])
            ->assertOk()
            ->assertJsonPath('applied.stage.reset_to', 'data_received');

        $registration->refresh();
        $this->assertSame('data_received', $registration->getRawOriginal('stage'));
        $this->assertSame(0, $registration->stageTransitions()->count());
    }

    #[Test]
    public function complete_is_idempotent_for_documents(): void
    {
        \Illuminate\Support\Facades\Storage::fake(config('filesystems.default'));
        $registration = Registration::factory()->create(['singapur_client_code' => '000123']);

        $payload = ['documents' => [
            ['type' => 'acta_signed', 'name' => 'ACTA.pdf', 'content_base64' => base64_encode('%PDF-1.4')],
        ]];

        $this->withHeader('X-Intake-Token', 'intake-secret')
            ->postJson("/api/v3/intake/000123/complete", $payload)->assertOk();
        // Segunda vez: no duplica.
        $this->withHeader('X-Intake-Token', 'intake-secret')
            ->postJson("/api/v3/intake/000123/complete", $payload)
            ->assertOk()
            ->assertJsonPath('applied.documents.0.action', 'skipped_exists');

        $this->assertSame(1, $registration->documents()->where('name', 'ACTA.pdf')->count());
    }
}
