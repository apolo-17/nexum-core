<?php

namespace Tests\Feature\Api\V3;

use App\Enums\DocumentTypeEnum;
use App\Models\Document;
use App\Models\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * China rejects a delivered document via the relay endpoint: it must be token-guarded and,
 * when valid, flag the document as rejected with the reason.
 */
class RelayDocumentRejectionTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-relay-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.singapur.webhook_secret' => self::SECRET]);
    }

    private function deliveredActa(): array
    {
        $registration = Registration::factory()->create(['singapur_client_code' => '000123']);
        $doc = Document::factory()->create([
            'registration_id' => $registration->id,
            'type' => DocumentTypeEnum::ACTA_PROTOCOLIZADA,
            'storage_path' => 'documents/acta.pdf',
            'relay_delivered_at' => now(),
        ]);

        return [$registration, $doc];
    }

    #[Test]
    public function it_rejects_a_document_and_records_the_reason(): void
    {
        [$registration, $doc] = $this->deliveredActa();

        $this->withHeaders(['X-Nexum-Secret' => self::SECRET])
            ->postJson("/api/v3/relay/company-documents/000123/incorporation_deed/reject", [
                'reason' => 'El acta no corresponde a esta empresa.',
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $doc->refresh();
        $this->assertNotNull($doc->relay_rejected_at);
        $this->assertSame('El acta no corresponde a esta empresa.', $doc->relay_rejection_reason);
    }

    #[Test]
    public function it_requires_the_relay_secret(): void
    {
        $this->deliveredActa();

        $this->withHeaders(['X-Nexum-Secret' => 'wrong'])
            ->postJson('/api/v3/relay/company-documents/000123/incorporation_deed/reject', ['reason' => 'x'])
            ->assertUnauthorized();
    }
}
