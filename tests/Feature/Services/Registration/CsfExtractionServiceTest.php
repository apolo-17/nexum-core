<?php

namespace Tests\Feature\Services\Registration;

use App\Enums\DocumentTypeEnum;
use App\Enums\RegistrationStageEnum;
use App\Enums\RegistrationStatusEnum;
use App\Models\Document;
use App\Models\Registration;
use App\Services\Document\DocumentAnalysisService;
use App\Services\Registration\CsfExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The CSF extraction fills the registration's RFC (only when empty) and fiscal-address
 * fields from what Claude reads on the Constancia de Situación Fiscal.
 */
class CsfExtractionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function csfFor(Registration $registration): Document
    {
        return Document::factory()->create([
            'registration_id' => $registration->id,
            'type' => DocumentTypeEnum::CSF,
            'storage_path' => 'documents/csf.pdf',
        ]);
    }

    private function fakeExtraction(array $fields): void
    {
        $this->mock(DocumentAnalysisService::class, function ($mock) use ($fields): void {
            $mock->shouldReceive('extractFromDocument')->andReturn($fields);
        });
    }

    #[Test]
    public function it_fills_rfc_and_fiscal_address_from_the_csf(): void
    {
        $registration = Registration::factory()->create(['rfc' => null]);
        $csf = $this->csfFor($registration);

        $this->fakeExtraction([
            'rfc' => 'ABC260101XY9',
            'fiscal_street' => 'Av. Reforma',
            'fiscal_ext_number' => '100',
            'fiscal_neighborhood' => 'Centro',
            'fiscal_municipality' => 'Cuauhtémoc',
            'fiscal_state' => 'Ciudad de México',
            'fiscal_postal_code' => '06000',
        ]);

        app(CsfExtractionService::class)->applyToRegistration($csf);

        $registration->refresh();
        $this->assertSame('ABC260101XY9', $registration->rfc);
        $this->assertSame('Av. Reforma', $registration->fiscal_street);
        $this->assertSame('Centro', $registration->fiscal_neighborhood);
        $this->assertSame('06000', $registration->fiscal_postal_code);
    }

    #[Test]
    public function it_does_not_overwrite_an_existing_rfc(): void
    {
        $registration = Registration::factory()->create(['rfc' => 'EXISTING0001']);
        $csf = $this->csfFor($registration);

        $this->fakeExtraction(['rfc' => 'OTHER1234567', 'fiscal_state' => 'Jalisco']);

        app(CsfExtractionService::class)->applyToRegistration($csf);

        $registration->refresh();
        $this->assertSame('EXISTING0001', $registration->rfc); // unchanged
        $this->assertSame('Jalisco', $registration->fiscal_state); // still filled
    }

    #[Test]
    public function it_advances_the_registration_to_sat_registration_once_the_rfc_is_obtained(): void
    {
        $registration = Registration::factory()->create([
            'rfc' => null,
            'status' => RegistrationStatusEnum::ACTIVE,
            'stage' => RegistrationStageEnum::ACTA_PREPARATION,
        ]);
        $csf = $this->csfFor($registration);

        $this->fakeExtraction(['rfc' => 'ABC260101XY9']);

        app(CsfExtractionService::class)->applyToRegistration($csf);

        $registration->refresh();
        $this->assertSame(RegistrationStageEnum::SAT_REGISTRATION, $registration->stage);
    }
}
