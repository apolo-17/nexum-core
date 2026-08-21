<?php

namespace Tests\Feature\Services\Mua;

use App\Enums\LegalNameStatusEnum;
use App\Models\LegalName;
use App\Models\Soldado;
use App\Services\Mua\MuaSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests that the bulk send never mis-assigns a FIEL.
 *
 * The SE allows ONE in-process denomination per account (RFC). Handing the same
 * soldier two at once gets both refused, so these cover the rule from the angle
 * that matters when the button fires a whole batch at the queue at the same time.
 */
class FielAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mua_bot.url' => 'https://bot.test',
            'services.mua_bot.api_key' => 'k',
        ]);
        Http::fake(['*' => Http::response(['accepted' => true], 200)]);
        $this->travelTo(now()->setTimezone('America/Mexico_City')->next('Wednesday')->setTime(11, 0));
    }

    #[Test]
    public function two_denominations_never_land_on_the_same_soldier(): void
    {
        $this->readySoldado('Única');
        $service = app(MuaSubmissionService::class);

        $first = $this->poolName('ALFA');
        $second = $this->poolName('BETA');

        $this->assertTrue($service->trySubmit($first));
        $this->assertFalse(
            $service->trySubmit($second),
            'The only soldier is occupied, so the second must defer instead of doubling up.'
        );

        $this->assertSame(LegalNameStatusEnum::SUBMITTING, $first->fresh()->status);
        $this->assertSame(LegalNameStatusEnum::WAIT, $second->fresh()->status);
        $this->assertNull($second->fresh()->soldado_id, 'A deferred name must not hold a FIEL.');
    }

    #[Test]
    public function a_batch_spreads_one_denomination_per_soldier(): void
    {
        $this->readySoldado('Uno');
        $this->readySoldado('Dos');
        $this->readySoldado('Tres');
        $service = app(MuaSubmissionService::class);

        $names = collect(['A', 'B', 'C'])->map(fn (string $n): LegalName => $this->poolName($n));
        foreach ($names as $name) {
            $this->assertTrue($service->trySubmit($name));
        }

        $assigned = $names->map(fn (LegalName $n): ?string => $n->fresh()->soldado_id);

        $this->assertCount(3, $assigned->filter()->unique(), 'Each name must get a distinct soldier.');
    }

    #[Test]
    public function a_soldier_stays_occupied_until_its_denomination_resolves(): void
    {
        $soldado = $this->readySoldado('Ocupada');
        $service = app(MuaSubmissionService::class);
        $held = $this->poolName('EN PROCESO');

        $service->trySubmit($held);
        $this->assertNull($service->findAvailableFiel(), 'SUBMITTING occupies the slot.');

        // Still occupied while the SE holds it.
        $held->update(['status' => LegalNameStatusEnum::PENDING->value]);
        $this->assertNull($service->findAvailableFiel(), 'PENDING occupies the slot.');

        $held->update(['status' => LegalNameStatusEnum::PROCESS->value]);
        $this->assertNull($service->findAvailableFiel(), 'PROCESS occupies the slot.');

        // Resolved — and only now does the slot free up.
        $held->update(['status' => LegalNameStatusEnum::APPROVED->value]);
        $this->assertSame($soldado->id, $service->findAvailableFiel()?->id);
    }

    #[Test]
    public function availability_reports_ready_free_and_busy(): void
    {
        $busy = $this->readySoldado('Ocupada');
        $this->readySoldado('Libre');
        // Flagged for MUA but without credentials: must not count as ready.
        Soldado::create([
            'name' => 'Incompleta',
            'email' => str()->random(8).'@soldados.mx',
            'is_active' => true,
            'available_for_mua' => true,
        ]);

        LegalName::create([
            'registration_id' => null,
            'name' => 'EN DICTAMEN',
            'company_type' => 'srl',
            'priority' => 1,
            'status' => LegalNameStatusEnum::PENDING,
            'soldado_id' => $busy->id,
        ]);

        $availability = app(MuaSubmissionService::class)->fielAvailability();

        $this->assertSame(2, $availability['ready']);
        $this->assertSame(1, $availability['free']);
        $this->assertSame(1, $availability['busy']);
        $this->assertSame(['Libre'], $availability['free_names']);
    }

    private function readySoldado(string $name): Soldado
    {
        $soldado = Soldado::create([
            'name' => $name,
            'email' => str()->random(8).'@soldados.mx',
            'is_active' => true,
            'available_for_mua' => true,
        ]);

        // Values are encrypted at rest, and submitToBot() decrypts them — storing
        // a raw string here would blow up on decrypt, not on the rule under test.
        foreach (['certificate', 'private_key', 'password'] as $type) {
            $soldado->credentials()->make(['type' => $type])
                ->setEncryptedValue("{$type}-de-prueba")
                ->save();
        }

        return $soldado->refresh();
    }

    private function poolName(string $name): LegalName
    {
        return LegalName::create([
            'registration_id' => null,
            'name' => $name,
            'company_type' => 'srl',
            'priority' => 1,
            'status' => LegalNameStatusEnum::WAIT,
        ]);
    }
}
