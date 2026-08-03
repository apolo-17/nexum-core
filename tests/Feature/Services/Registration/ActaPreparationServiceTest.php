<?php

namespace Tests\Feature\Services\Registration;

use App\Models\Registration;
use App\Models\Soldado;
use App\Services\Registration\ActaPreparationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for the apoderados block compiled into the acta template data.
 */
class ActaPreparationServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_compiles_the_acta_apoderados_from_the_legal_representatives(): void
    {
        $registration = Registration::factory()->create(['company_type' => 'SRL de CV']);
        $registration->shareholders()->create([
            'name' => 'ZHONGLING TONG', 'nationality' => 'China', 'role' => 'legal_representative',
            'participation_percentage' => 90,
        ]);

        $gabriel = Soldado::create(['name' => 'Gabriel Ulaje Correa', 'rfc' => 'UACG921001TF2', 'curp' => 'UACG921001HQTLRB02', 'email' => 'fulburi@gmail.com', 'is_active' => true]);
        $keilyn = Soldado::create(['name' => 'Keilyn Yulieth Ramírez Rey', 'rfc' => 'RARK041205KX8', 'email' => 'keilyn@example.com', 'is_active' => true]);
        $registration->soldados()->attach([
            $gabriel->id => ['role' => 'legal_representative'],
            $keilyn->id => ['role' => 'legal_representative'],
        ]);

        $data = app(ActaPreparationService::class)->compile($registration);

        $this->assertSame(2, $data['numero_apoderados']);
        $names = array_column($data['apoderados'], 'apoderado_nombre');
        $this->assertContains('GABRIEL ULAJE CORREA', $names);
        $this->assertContains('KEILYN YULIETH RAMÍREZ REY', $names);
        // RFC travels with each apoderado (what the acta needs).
        $gabrielBlock = collect($data['apoderados'])->firstWhere('apoderado_nombre', 'GABRIEL ULAJE CORREA');
        $this->assertSame('UACG921001TF2', $gabrielBlock['apoderado_rfc']);
    }
}
