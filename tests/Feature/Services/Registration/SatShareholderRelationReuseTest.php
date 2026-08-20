<?php

namespace Tests\Feature\Services\Registration;

use App\Enums\DocumentTypeEnum;
use App\Models\Document;
use App\Models\Registration;
use App\Services\Registration\SatShareholderRelationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La relación de socios (.xlsx) es la misma para la empresa, así que la de la primera
 * cita (RFC) debe REUTILIZARSE en la cita de e.firma en vez de regenerar un archivo nuevo.
 */
class SatShareholderRelationReuseTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function reuses_the_existing_relation_instead_of_regenerating(): void
    {
        Storage::fake('s3');

        $registration = Registration::factory()->create();
        $path = "documents/{$registration->id}/sat_relation/rel.xlsx";
        Storage::disk('s3')->put($path, 'contenido-xlsx');

        $existing = Document::factory()->create([
            'registration_id' => $registration->id,
            'type' => DocumentTypeEnum::SAT_SHAREHOLDER_RELATION,
            'storage_path' => $path,
        ]);

        $result = app(SatShareholderRelationService::class)->getOrGenerate($registration);

        // Devuelve el MISMO documento y no crea uno nuevo.
        $this->assertSame($existing->id, $result->id);
        $this->assertSame(
            1,
            Document::where('registration_id', $registration->id)
                ->where('type', DocumentTypeEnum::SAT_SHAREHOLDER_RELATION->value)
                ->count(),
        );
    }
}
