<?php

namespace Tests\Feature\Filament;

use App\Enums\LegalNameStatusEnum;
use App\Filament\Widgets\MuaCapacityOverview;
use App\Models\LegalName;
use App\Models\Soldado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for the MUA capacity widget on the denominations screen.
 *
 * Free soldiers is the ceiling on how many denominations can be sent at once, so
 * the number has to be readable without opening the send dialog.
 */
class MuaCapacityOverviewTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_shows_free_soldiers_by_name(): void
    {
        $this->readySoldado('Haziel Lavalle');
        $busy = $this->readySoldado('Ivana Zuñiga');
        $this->inProcessNameFor($busy);

        Livewire::test(MuaCapacityOverview::class)
            ->assertSee('Soldados libres')
            ->assertSee('Haziel Lavalle')
            ->assertSee('Soldados ocupados');
    }

    #[Test]
    public function it_warns_when_a_soldier_flagged_for_mua_has_no_complete_fiel(): void
    {
        $this->readySoldado('Completa');
        // Flagged for MUA but with no credentials: silently skipped when choosing an
        // account, so it must be called out rather than counted as available.
        Soldado::create([
            'name' => 'Sin FIEL',
            'email' => 'sinfiel@soldados.mx',
            'is_active' => true,
            'available_for_mua' => true,
        ]);

        Livewire::test(MuaCapacityOverview::class)
            ->assertSee('1 soldado(s) sin FIEL completa');
    }

    #[Test]
    public function it_says_the_queue_waits_when_every_soldier_is_occupied(): void
    {
        $busy = $this->readySoldado('Ocupada');
        $this->inProcessNameFor($busy);

        LegalName::create([
            'registration_id' => null,
            'name' => 'EN COLA',
            'company_type' => 'srl',
            'priority' => 1,
            'status' => LegalNameStatusEnum::WAIT,
        ]);

        Livewire::test(MuaCapacityOverview::class)
            ->assertSee('Ninguno puede tomar una denominación ahora')
            ->assertSee('Esperan a que se libere un soldado');
    }

    #[Test]
    public function it_reports_spare_capacity_when_there_are_more_soldiers_than_names(): void
    {
        $this->readySoldado('Uno');
        $this->readySoldado('Dos');

        LegalName::create([
            'registration_id' => null,
            'name' => 'UNICA',
            'company_type' => 'srl',
            'priority' => 1,
            'status' => LegalNameStatusEnum::WAIT,
        ]);

        Livewire::test(MuaCapacityOverview::class)
            ->assertSee('Hay capacidad para enviarlas todas');
    }

    private function readySoldado(string $name): Soldado
    {
        $soldado = Soldado::create([
            'name' => $name,
            'email' => str()->random(8).'@soldados.mx',
            'is_active' => true,
            'available_for_mua' => true,
        ]);

        foreach (['certificate', 'private_key', 'password'] as $type) {
            $soldado->credentials()->make(['type' => $type])
                ->setEncryptedValue("{$type}-de-prueba")
                ->save();
        }

        return $soldado->refresh();
    }

    private function inProcessNameFor(Soldado $soldado): LegalName
    {
        return LegalName::create([
            'registration_id' => null,
            'name' => 'EN DICTAMEN',
            'company_type' => 'srl',
            'priority' => 1,
            'status' => LegalNameStatusEnum::PENDING,
            'soldado_id' => $soldado->id,
        ]);
    }
}
