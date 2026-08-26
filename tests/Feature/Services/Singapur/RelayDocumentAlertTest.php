<?php

namespace Tests\Feature\Services\Singapur;

use App\Enums\DocumentTypeEnum;
use App\Models\Document;
use App\Services\Singapur\RelayDocumentAlertService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The relay is alerted only about final deliverables China pulls. The incorporation deed
 * China needs is the PROTOCOLIZED acta (notarized escritura), not the pre-notary signed draft.
 */
class RelayDocumentAlertTest extends TestCase
{
    private function doc(DocumentTypeEnum $type, ?string $path = 'documents/x/file.pdf'): Document
    {
        return new Document(['type' => $type, 'storage_path' => $path]);
    }

    #[Test]
    public function the_protocolized_acta_is_the_incorporation_deed_deliverable(): void
    {
        $service = app(RelayDocumentAlertService::class);

        $this->assertTrue($service->shouldAlert($this->doc(DocumentTypeEnum::ACTA_PROTOCOLIZADA)));
        $this->assertSame('incorporation_deed', $service->slugFor(DocumentTypeEnum::ACTA_PROTOCOLIZADA));
    }

    #[Test]
    public function the_signed_draft_and_the_render_draft_are_not_sent_to_china(): void
    {
        $service = app(RelayDocumentAlertService::class);

        $this->assertFalse($service->shouldAlert($this->doc(DocumentTypeEnum::ACTA_SIGNED)));
        $this->assertFalse($service->shouldAlert($this->doc(DocumentTypeEnum::ACTA_DRAFT)));
        $this->assertNull($service->slugFor(DocumentTypeEnum::ACTA_SIGNED));
    }

    #[Test]
    public function other_deliverables_still_alert(): void
    {
        $service = app(RelayDocumentAlertService::class);

        $this->assertSame('tax_status_certificate', $service->slugFor(DocumentTypeEnum::CSF));
        $this->assertSame('e_firma', $service->slugFor(DocumentTypeEnum::EFIRMA));
        $this->assertTrue($service->shouldAlert($this->doc(DocumentTypeEnum::CSF)));
    }

    #[Test]
    public function a_deliverable_without_a_stored_file_does_not_alert(): void
    {
        $service = app(RelayDocumentAlertService::class);

        $this->assertFalse($service->shouldAlert($this->doc(DocumentTypeEnum::ACTA_PROTOCOLIZADA, null)));
    }
}
