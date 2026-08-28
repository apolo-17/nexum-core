<?php

namespace Tests\Feature\Api\V3;

use App\Enums\DocumentTypeEnum;
use App\Models\Document;
use App\Models\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The relay's pre-signed download endpoint must hand out the compressed derivative
 * when one exists (oversized scanned actas), and fall back to the original otherwise.
 */
class CompanyDocumentRelayServesDerivativeTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-relay-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.singapur.webhook_secret' => self::SECRET]);
    }

    private function acta(?string $relayPath): void
    {
        $registration = Registration::factory()->create(['singapur_client_code' => '000123']);
        Document::factory()->create([
            'registration_id' => $registration->id,
            'type' => DocumentTypeEnum::ACTA_PROTOCOLIZADA,
            'storage_path' => 'documents/acta-original.pdf',
            'relay_storage_path' => $relayPath,
        ]);
    }

    #[Test]
    public function it_signs_the_compressed_derivative_when_present(): void
    {
        $this->acta('relay-compressed/acta.pdf');

        Storage::shouldReceive('temporaryUrl')
            ->once()
            ->with('relay-compressed/acta.pdf', \Mockery::any())
            ->andReturn('https://r2.example/signed');

        $this->withHeaders(['X-Nexum-Secret' => self::SECRET])
            ->getJson('/api/v3/relay/company-documents/000123/incorporation_deed')
            ->assertOk()
            ->assertJson(['url' => 'https://r2.example/signed']);
    }

    #[Test]
    public function it_signs_the_original_when_there_is_no_derivative(): void
    {
        $this->acta(null);

        Storage::shouldReceive('temporaryUrl')
            ->once()
            ->with('documents/acta-original.pdf', \Mockery::any())
            ->andReturn('https://r2.example/original');

        $this->withHeaders(['X-Nexum-Secret' => self::SECRET])
            ->getJson('/api/v3/relay/company-documents/000123/incorporation_deed')
            ->assertOk()
            ->assertJson(['url' => 'https://r2.example/original']);
    }
}
