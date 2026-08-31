<?php

namespace Tests\Feature\Services\Registration;

use App\Enums\DocumentTypeEnum;
use App\Enums\LegalNameStatusEnum;
use App\Models\Document;
use App\Models\LegalName;
use App\Models\Registration;
use App\Models\Shareholder;
use App\Models\Soldado;
use App\Services\Registration\ActaCompletenessValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The completeness validator returns no issues for a fully-populated registration and a
 * precise, human-readable issue for each missing piece.
 */
class ActaCompletenessValidatorTest extends TestCase
{
    use RefreshDatabase;

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

    #[Test]
    public function a_complete_registration_has_no_issues(): void
    {
        $issues = (new ActaCompletenessValidator)->validate($this->completeRegistration());

        $this->assertSame([], $issues);
    }

    #[Test]
    public function it_flags_a_shareholder_without_a_passport_document(): void
    {
        $registration = $this->completeRegistration();
        // Remove the passport document of shareholder 2.
        $registration->documents()->where('shareholder_index', 2)->delete();

        $issues = (new ActaCompletenessValidator)->validate($registration->fresh());

        $this->assertTrue(collect($issues)->contains(fn (string $i): bool => str_contains($i, 'no tiene su pasaporte')));
    }

    #[Test]
    public function it_flags_a_denomination_without_folio(): void
    {
        $registration = $this->completeRegistration();
        $registration->primaryLegalName->update(['clave_unica_denominacion' => null]);

        $issues = (new ActaCompletenessValidator)->validate($registration->fresh());

        $this->assertTrue(collect($issues)->contains(fn (string $i): bool => str_contains($i, 'folio (CUD)')));
    }

    #[Test]
    public function it_flags_too_few_apoderados(): void
    {
        $registration = $this->completeRegistration();
        // Detach all but one apoderado.
        $keep = $registration->legalRepresentatives()->first();
        $registration->soldados()->detach();
        $registration->soldados()->attach($keep->id, ['role' => 'legal_representative']);

        $issues = (new ActaCompletenessValidator)->validate($registration->fresh());

        $this->assertTrue(collect($issues)->contains(fn (string $i): bool => str_contains($i, 'al menos 3 apoderados')));
    }

    #[Test]
    public function it_flags_missing_company_fields(): void
    {
        $registration = $this->completeRegistration();
        $registration->update(['capital_social' => null, 'company_object' => null]);

        $issues = (new ActaCompletenessValidator)->validate($registration->fresh());

        $this->assertTrue(collect($issues)->contains(fn (string $i): bool => str_contains($i, 'capital social')));
        // El objeto social ya no se valida: es boilerplate idéntico para todas las empresas.
        $this->assertFalse(collect($issues)->contains(fn (string $i): bool => str_contains($i, 'objeto social')));
    }
}
