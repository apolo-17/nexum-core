<?php

namespace Tests\Feature\Services\Registration;

use App\Enums\DocumentTypeEnum;
use App\Enums\RegistrationStageEnum;
use App\Enums\RegistrationStatusEnum;
use App\Models\Document;
use App\Models\Registration;
use App\Services\Registration\RegistrationCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A company becomes operative once it has all three SAT deliverables: RFC, CSF (with a
 * fiscal address) and the company's e.firma safeguarded.
 */
class RegistrationCompletionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function completeRegistration(): Registration
    {
        $registration = Registration::factory()->create([
            'status' => RegistrationStatusEnum::ACTIVE,
            'stage' => RegistrationStageEnum::EFIRMA_APPOINTMENT,
            'rfc' => 'ABC260101XY9',
            'fiscal_postal_code' => '06000',
            'company_fiel_cer_path' => 'fiel/company.cer',
            'company_fiel_key_path' => 'fiel/company.key',
            'company_fiel_password' => 'secret-pass',
        ]);

        Document::factory()->create([
            'registration_id' => $registration->id,
            'type' => DocumentTypeEnum::CSF,
        ]);

        return $registration;
    }

    #[Test]
    public function it_marks_a_fully_delivered_company_as_operative(): void
    {
        $registration = $this->completeRegistration();

        app(RegistrationCompletionService::class)->evaluate($registration);

        $registration->refresh();
        $this->assertSame(RegistrationStageEnum::COMPLETED, $registration->stage);
        $this->assertSame(RegistrationStatusEnum::COMPLETED, $registration->status);
    }

    #[Test]
    public function it_does_not_complete_when_the_efirma_is_missing(): void
    {
        $registration = $this->completeRegistration();
        $registration->update(['company_fiel_cer_path' => null]);

        $this->assertFalse(app(RegistrationCompletionService::class)->isComplete($registration));

        app(RegistrationCompletionService::class)->evaluate($registration);

        $registration->refresh();
        $this->assertNotSame(RegistrationStageEnum::COMPLETED, $registration->stage);
    }

    #[Test]
    public function it_does_not_complete_without_a_csf_document(): void
    {
        $registration = $this->completeRegistration();
        $registration->documents()->delete();

        $this->assertFalse(app(RegistrationCompletionService::class)->isComplete($registration));
    }
}
