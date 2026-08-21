<?php

namespace Tests\Feature\Jobs;

use App\Enums\DocumentTypeEnum;
use App\Enums\LegalNameStatusEnum;
use App\Jobs\BuildActaRenderJob;
use App\Models\Document;
use App\Models\LegalName;
use App\Models\Registration;
use App\Models\Shareholder;
use App\Models\Soldado;
use App\Models\User;
use App\Notifications\ActaRenderIncomplete;
use App\Notifications\ActaRenderReady;
use App\Services\Registration\ActaCompletenessValidator;
use App\Services\Registration\ActaPreparationService;
use App\Services\Registration\KycReconciliationResult;
use App\Services\Registration\KycReconciliationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The render job either builds an ACTA_DRAFT and alerts "ready", or — when data is missing —
 * alerts "could not complete" without producing a draft.
 */
class BuildActaRenderJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    private function completeRegistration(): Registration
    {
        $registration = Registration::factory()->create([
            'capital_social' => 10000,
            'company_object' => 'La sociedad tiene por objeto…',
        ]);

        LegalName::create([
            'registration_id' => $registration->id,
            'name' => 'EMPRESA DE PRUEBA',
            'priority' => 1,
            'status' => LegalNameStatusEnum::APPROVED->value,
            'clave_unica_denominacion' => 'A202600000000000000',
            'authorization_timestamp' => now(),
        ]);

        foreach ([['SOCIO UNO', 60], ['SOCIO DOS', 40]] as $i => [$name, $percentage]) {
            $index = $i + 1;
            Shareholder::factory()->create([
                'registration_id' => $registration->id,
                'name' => $name,
                'nationality' => 'china',
                'passport_number' => "ES{$index}000000",
                'participation_percentage' => $percentage,
            ]);
            Document::factory()->create([
                'registration_id' => $registration->id,
                'type' => DocumentTypeEnum::PASSPORT,
                'shareholder_index' => $index,
                'storage_path' => "documents/passport_{$index}.jpg",
            ]);
        }

        for ($k = 1; $k <= 3; $k++) {
            $soldado = Soldado::create([
                'name' => "Apoderado {$k}",
                'email' => "ap{$k}@nexum.test",
                'rfc' => 'APO'.str_pad((string) $k, 6, '0', STR_PAD_LEFT).'ABC',
                'is_active' => true,
                'available_as_legal_representative' => true,
            ]);
            $registration->soldados()->attach($soldado->id, ['role' => 'legal_representative']);
        }

        return $registration->fresh();
    }

    private function runJob(Registration $registration): void
    {
        (new BuildActaRenderJob($registration->id))->handle(
            app(ActaCompletenessValidator::class),
            app(KycReconciliationService::class),
            app(ActaPreparationService::class),
        );
    }

    #[Test]
    public function it_alerts_when_the_render_cannot_be_completed(): void
    {
        Notification::fake();
        $admin = $this->admin();
        // Never reached, but resolved as a handle() dependency — mock so no vision runs.
        $this->mock(KycReconciliationService::class);

        $registration = Registration::factory()->create(); // minimal → many gaps

        $this->runJob($registration);

        Notification::assertSentTo($admin, ActaRenderIncomplete::class);
        $this->assertDatabaseMissing('documents', [
            'registration_id' => $registration->id,
            'type' => DocumentTypeEnum::ACTA_DRAFT->value,
        ]);
    }

    #[Test]
    public function it_builds_the_draft_and_alerts_ready_when_complete(): void
    {
        Notification::fake();
        $admin = $this->admin();

        $this->mock(KycReconciliationService::class, function ($mock): void {
            $mock->shouldReceive('reconcile')->andReturn(new KycReconciliationResult([], []));
        });
        $this->mock(ActaPreparationService::class, function ($mock): void {
            $mock->shouldReceive('compile')->andReturn(['dummy' => true]);
        });

        $registration = $this->completeRegistration();

        $this->runJob($registration);

        $this->assertDatabaseHas('documents', [
            'registration_id' => $registration->id,
            'type' => DocumentTypeEnum::ACTA_DRAFT->value,
        ]);
        Notification::assertSentTo($admin, ActaRenderReady::class);
    }
}
