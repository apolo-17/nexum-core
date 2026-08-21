<?php

namespace Tests\Feature\Services\Mua;

use App\Enums\LegalNameStatusEnum;
use App\Models\LegalName;
use App\Models\Soldado;
use App\Services\Mua\MuaSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests for how findAvailableFiel() picks the account for the next submission.
 *
 * Selection used to take the oldest-created eligible soldado, so every dispatch
 * landed on the same FIEL. When the SE refused that one account, the whole queue
 * bounced off it while healthy FIELs sat unused.
 */
class FielRotationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_prefers_a_fiel_that_has_never_submitted(): void
    {
        $veteran = $this->readySoldado('Veterana');
        $this->resolvedDenomination($veteran, now()->subDay());

        $fresh = $this->readySoldado('Nueva');

        $this->assertSame($fresh->id, $this->service()->findAvailableFiel()?->id);
    }

    #[Test]
    public function it_rotates_to_the_least_recently_used_fiel(): void
    {
        $recent = $this->readySoldado('Reciente');
        $this->resolvedDenomination($recent, now()->subHour());

        $stale = $this->readySoldado('Antigua');
        $this->resolvedDenomination($stale, now()->subWeek());

        $this->assertSame(
            $stale->id,
            $this->service()->findAvailableFiel()?->id,
            'The FIEL idle longest must be tried first.',
        );
    }

    #[Test]
    public function it_skips_a_fiel_that_is_parked_out_of_the_rotation(): void
    {
        $parked = $this->readySoldado('Bloqueada');
        $parked->update([
            'available_for_mua' => false,
            'mua_blocked_reason' => 'Solo se pueden tener 1 solicitud(es) en proceso.',
            'mua_blocked_at' => now(),
        ]);

        $this->assertNull($this->service()->findAvailableFiel());
    }

    #[Test]
    public function it_skips_a_fiel_already_holding_an_in_process_denomination(): void
    {
        $busy = $this->readySoldado('Ocupada');
        LegalName::create([
            'registration_id' => null,
            'name' => 'FENG BO COMERCIO',
            'company_type' => 'srl',
            'priority' => 1,
            'status' => LegalNameStatusEnum::PENDING,
            'soldado_id' => $busy->id,
        ]);

        $this->assertNull($this->service()->findAvailableFiel());
    }

    #[Test]
    public function a_name_returned_to_the_queue_does_not_hold_its_fiel(): void
    {
        // soldado_id survives a failed dispatch so a late `submitted` can use it.
        // Occupancy is derived from status, so a WAIT name must free the slot.
        $soldado = $this->readySoldado('Reintentable');
        LegalName::create([
            'registration_id' => null,
            'name' => 'ZHI HUA COMERCIO DIGITAL',
            'company_type' => 'srl',
            'priority' => 1,
            'status' => LegalNameStatusEnum::WAIT,
            'soldado_id' => $soldado->id,
        ]);

        $this->assertSame($soldado->id, $this->service()->findAvailableFiel()?->id);
    }

    private function service(): MuaSubmissionService
    {
        return app(MuaSubmissionService::class);
    }

    private function readySoldado(string $name): Soldado
    {
        $soldado = Soldado::create([
            'name' => $name,
            'email' => str()->random(8).'@soldados.mx',
            'is_active' => true,
            'available_for_mua' => true,
        ]);

        // isReadyForMua() needs all three FIEL parts present.
        foreach (['certificate', 'private_key', 'password'] as $type) {
            $soldado->credentials()->create(['type' => $type, 'credential' => 'x']);
        }

        return $soldado->refresh();
    }

    private function resolvedDenomination(Soldado $soldado, $submittedAt): LegalName
    {
        return LegalName::create([
            'registration_id' => null,
            'name' => 'RESUELTA '.str()->random(5),
            'company_type' => 'srl',
            'priority' => 1,
            'status' => LegalNameStatusEnum::APPROVED,
            'soldado_id' => $soldado->id,
            'submitted_at' => $submittedAt,
        ]);
    }
}
