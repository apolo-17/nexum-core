<?php

namespace Tests\Feature\Services\Registration;

use App\Models\Registration;
use App\Models\Soldado;
use App\Services\Registration\ApoderadoAssignmentService;
use App\Services\Registration\Exceptions\NotEnoughApoderadosException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The apoderado assignment picks 3–4 least-loaded eligible soldados so actas are spread
 * evenly, and never reassigns a registration that already has legal representatives.
 */
class ApoderadoAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ApoderadoAssignmentService
    {
        return app(ApoderadoAssignmentService::class);
    }

    private function eligibleSoldado(int $i, array $overrides = []): Soldado
    {
        return Soldado::create(array_merge([
            'name' => "Soldado {$i}",
            'email' => "s{$i}@nexum.test",
            'rfc' => 'SOL'.str_pad((string) $i, 6, '0', STR_PAD_LEFT).'ABC',
            'is_active' => true,
            'available_as_legal_representative' => true,
        ], $overrides));
    }

    private function giveActas(Soldado $soldado, int $count): void
    {
        for ($k = 0; $k < $count; $k++) {
            Registration::factory()->create()
                ->soldados()->attach($soldado->id, ['role' => 'legal_representative']);
        }
    }

    #[Test]
    public function it_assigns_between_three_and_four_legal_representatives(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->eligibleSoldado($i);
        }
        $registration = Registration::factory()->create();

        $assigned = $this->service()->assign($registration);

        $this->assertGreaterThanOrEqual(3, $assigned->count());
        $this->assertLessThanOrEqual(4, $assigned->count());
        $this->assertSame($assigned->count(), $registration->legalRepresentatives()->count());
    }

    #[Test]
    public function it_picks_the_least_loaded_soldados(): void
    {
        $fresh = collect(range(1, 4))->map(fn (int $i): Soldado => $this->eligibleSoldado($i));
        $busy = collect([5, 6])->map(function (int $i): Soldado {
            $s = $this->eligibleSoldado($i);
            $this->giveActas($s, 5);

            return $s;
        });

        $assigned = $this->service()->assign(Registration::factory()->create());

        // The four soldados with zero actas are chosen; the two busy ones are not.
        $this->assertEquals(
            $fresh->pluck('id')->sort()->values()->all(),
            $assigned->pluck('id')->sort()->values()->all(),
        );
        foreach ($busy as $b) {
            $this->assertFalse($assigned->contains('id', $b->id));
        }
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->eligibleSoldado($i);
        }
        $registration = Registration::factory()->create();

        $first = $this->service()->assign($registration);
        $second = $this->service()->assign($registration);

        $this->assertEquals(
            $first->pluck('id')->sort()->values()->all(),
            $second->pluck('id')->sort()->values()->all(),
        );
        $this->assertSame($first->count(), $registration->legalRepresentatives()->count());
    }

    #[Test]
    public function it_throws_when_not_enough_eligible_soldados(): void
    {
        $this->eligibleSoldado(1);
        $this->eligibleSoldado(2); // only two eligible, below the minimum of three

        $this->expectException(NotEnoughApoderadosException::class);

        $this->service()->assign(Registration::factory()->create());
    }

    #[Test]
    public function it_excludes_ineligible_soldados(): void
    {
        collect(range(1, 3))->each(fn (int $i) => $this->eligibleSoldado($i));
        $bad = [
            $this->eligibleSoldado(10, ['is_active' => false]),
            $this->eligibleSoldado(11, ['available_as_legal_representative' => false]),
            $this->eligibleSoldado(12, ['rfc' => null]),
            $this->eligibleSoldado(13, ['email' => null]),
        ];

        $assigned = $this->service()->assign(Registration::factory()->create());

        $this->assertSame(3, $assigned->count());
        foreach ($bad as $soldado) {
            $this->assertFalse($assigned->contains('id', $soldado->id));
        }
    }

    #[Test]
    public function it_distributes_actas_evenly_across_soldados(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->eligibleSoldado($i);
        }

        for ($k = 0; $k < 10; $k++) {
            $this->service()->assign(Registration::factory()->create());
        }

        $counts = Soldado::withCount([
            'registrations as c' => fn ($q) => $q->where('registration_soldado.role', 'legal_representative'),
        ])->pluck('c');

        // With least-loaded-first selection the workload stays tightly balanced and nobody is starved.
        $this->assertLessThanOrEqual(2, $counts->max() - $counts->min());
        $this->assertSame(0, $counts->filter(fn (int $c): bool => $c === 0)->count());
    }
}
