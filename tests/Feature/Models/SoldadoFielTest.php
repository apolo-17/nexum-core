<?php

namespace Tests\Feature\Models;

use App\Models\Soldado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A soldado can attend an e.firma appointment only if their personal FIEL is still valid, and a
 * legal-rep profile is "complete" when we hold RFC + CURP + a vigente FIEL.
 */
class SoldadoFielTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function fiel_is_vigente_only_with_a_future_date(): void
    {
        $this->assertTrue(new Soldado(['fiel_valid_until' => now()->addMonth()])->fielVigente());
        $this->assertFalse(new Soldado(['fiel_valid_until' => now()->subDay()])->fielVigente());
        $this->assertFalse(new Soldado(['fiel_valid_until' => null])->fielVigente());
    }

    #[Test]
    public function profile_is_complete_with_rfc_curp_and_a_vigente_fiel(): void
    {
        $complete = new Soldado([
            'rfc' => 'AOMU960129V22',
            'curp' => 'AOMU960129HDFXXX01',
            'fiel_valid_until' => now()->addYear(),
        ]);
        $this->assertTrue($complete->legalRepProfileComplete());

        $noCurp = new Soldado([
            'rfc' => 'AOMU960129V22',
            'curp' => null,
            'fiel_valid_until' => now()->addYear(),
        ]);
        $this->assertFalse($noCurp->legalRepProfileComplete());

        $expiredFiel = new Soldado([
            'rfc' => 'AOMU960129V22',
            'curp' => 'AOMU960129HDFXXX01',
            'fiel_valid_until' => now()->subDay(),
        ]);
        $this->assertFalse($expiredFiel->legalRepProfileComplete());
    }
}
