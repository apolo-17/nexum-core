<?php

namespace Tests\Feature\Services\Singapur;

use App\Enums\DocumentTypeEnum;
use App\Models\Document;
use App\Models\Registration;
use App\Services\Singapur\ChinaDeliverablesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Per-registration status of the five deliverables China needs: delivered (confirmed),
 * pending (we have it, not sent) and missing (we don't have it).
 */
class ChinaDeliverablesServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_delivered_pending_and_missing_states(): void
    {
        $registration = Registration::factory()->create();

        // Acta: entregada (China confirmó).
        Document::factory()->create([
            'registration_id' => $registration->id,
            'type' => DocumentTypeEnum::ACTA_PROTOCOLIZADA,
            'storage_path' => 'documents/acta.pdf',
            'relay_delivered_at' => now(),
            'relay_drive_url' => 'https://drive.google.com/x',
        ]);
        // CSF: la tenemos pero no se ha enviado.
        Document::factory()->create([
            'registration_id' => $registration->id,
            'type' => DocumentTypeEnum::CSF,
            'storage_path' => 'documents/csf.pdf',
            'relay_delivered_at' => null,
        ]);
        // RPP, domicilio y e.firma: no existen → missing.

        $svc = app(ChinaDeliverablesService::class);
        $status = collect($svc->statusFor($registration))->keyBy('type');

        $this->assertSame('delivered', $status[DocumentTypeEnum::ACTA_PROTOCOLIZADA->value]['state']);
        $this->assertSame('https://drive.google.com/x', $status[DocumentTypeEnum::ACTA_PROTOCOLIZADA->value]['drive_url']);
        $this->assertSame('pending', $status[DocumentTypeEnum::CSF->value]['state']);
        $this->assertSame('missing', $status[DocumentTypeEnum::RPP_REGISTRATION->value]['state']);
        $this->assertSame('missing', $status[DocumentTypeEnum::EFIRMA->value]['state']);

        $this->assertSame(1, $svc->deliveredCount($registration));
        $this->assertSame(5, $svc->total());
    }
}
