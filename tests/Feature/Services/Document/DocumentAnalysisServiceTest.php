<?php

namespace Tests\Feature\Services\Document;

use App\Enums\DocumentTypeEnum;
use App\Models\Document;
use App\Models\DocumentAnalysis;
use App\Services\Document\DocumentAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for DocumentAnalysisService.
 *
 * Every test fakes both the storage disk and the Anthropic HTTP endpoint, so the
 * suite never reads a real file nor calls the real Claude API — no ANTHROPIC_API_KEY
 * is required to run it. Tests assert OUR logic: payload construction, JSON parsing
 * (including markdown-fenced responses), column mapping, error handling and idempotency.
 */
class DocumentAnalysisServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Reset fakes and inject a dummy API key before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();
        // A fake key is enough — Http::fake() intercepts the request, so it is never used to authenticate.
        config(['services.anthropic.api_key' => 'test-key']);
    }

    /**
     * Create an analysable document with a backing file in fake storage.
     *
     * @param  DocumentTypeEnum  $type  The document type to assign.
     * @param  string  $extension  File extension that drives the media type (jpg, png, pdf…).
     */
    private function makeDocument(DocumentTypeEnum $type, string $extension = 'jpg'): Document
    {
        $document = Document::factory()->create([
            'type' => $type,
            'storage_path' => "documents/000001/file.{$extension}",
        ]);

        Storage::put($document->storage_path, 'fake-binary-content');

        return $document;
    }

    /**
     * Fake the Anthropic endpoint to return a Claude-shaped 200 response.
     *
     * @param  array<string, mixed>|string  $payload  Decoded fields (json-encoded) or raw text to place in content[0].text.
     */
    private function fakeClaude(array|string $payload): void
    {
        $text = is_array($payload) ? json_encode($payload) : $payload;

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => $text]],
            ], 200),
        ]);
    }

    /**
     * Resolve a fresh service instance.
     */
    private function service(): DocumentAnalysisService
    {
        return new DocumentAnalysisService;
    }

    /**
     * A passport is parsed into identity fields and the record is marked analyzed.
     */
    #[Test]
    public function it_extracts_passport_fields_and_marks_analyzed(): void
    {
        $this->fakeClaude([
            'document_number' => 'G12345678',
            'gender' => 'M',
            'nationality' => 'China',
            'birthdate' => '1990-05-15',
            'birthplace' => 'Beijing, China',
            'expiry_date' => '2030-01-01',
        ]);

        $document = $this->makeDocument(DocumentTypeEnum::PASSPORT);

        $analysis = $this->service()->analyse($document);

        $this->assertInstanceOf(DocumentAnalysis::class, $analysis);
        $this->assertTrue($analysis->analyzed);
        $this->assertSame('G12345678', $analysis->document_number);
        $this->assertSame('M', $analysis->gender);
        $this->assertSame('China', $analysis->nationality);
        $this->assertSame('1990-05-15', $analysis->birthdate->format('Y-m-d'));
        $this->assertSame('Beijing, China', $analysis->birthplace);
        $this->assertNull($analysis->error_message);
        $this->assertDatabaseHas('document_analyses', [
            'document_id' => $document->id,
            'analyzed' => true,
        ]);
    }

    /**
     * The request targets Opus 4.6 with the authenticated headers and an inline image block.
     */
    #[Test]
    public function it_sends_the_opus_model_with_an_inline_image_block(): void
    {
        $this->fakeClaude(['document_number' => 'X1']);

        $document = $this->makeDocument(DocumentTypeEnum::PASSPORT, 'jpg');

        $this->service()->analyse($document);

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('x-api-key', 'test-key')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && $body['model'] === 'claude-opus-4-6'
                && $body['messages'][0]['content'][0]['type'] === 'image'
                && $body['messages'][0]['content'][0]['source']['media_type'] === 'image/jpeg'
                && $body['messages'][0]['content'][0]['source']['data'] === base64_encode('fake-binary-content');
        });
    }

    /**
     * A PDF is sent as a 'document' content block instead of an 'image' block.
     */
    #[Test]
    public function it_uses_a_document_block_for_pdf_files(): void
    {
        $this->fakeClaude(['document_number' => 'X1']);

        $document = $this->makeDocument(DocumentTypeEnum::PASSPORT, 'pdf');

        $this->service()->analyse($document);

        Http::assertSent(function (Request $request): bool {
            $block = $request->data()['messages'][0]['content'][0];

            return $block['type'] === 'document'
                && $block['source']['media_type'] === 'application/pdf';
        });
    }

    /**
     * Proof-of-address documents map address and country fields.
     */
    #[Test]
    public function it_extracts_proof_of_address_fields(): void
    {
        $this->fakeClaude([
            'address' => 'Calle 123, CDMX',
            'country_of_residence' => 'Mexico',
        ]);

        $document = $this->makeDocument(DocumentTypeEnum::KYC_PROOF_OF_ADDRESS);

        $analysis = $this->service()->analyse($document);

        $this->assertTrue($analysis->analyzed);
        $this->assertSame('Calle 123, CDMX', $analysis->address);
        $this->assertSame('Mexico', $analysis->country_of_residence);
    }

    /**
     * Marriage certificates map the matrimonial regime.
     */
    #[Test]
    public function it_extracts_the_matrimonial_regime(): void
    {
        $this->fakeClaude(['matrimonial_regime' => 'sociedad_conyugal']);

        $document = $this->makeDocument(DocumentTypeEnum::KYC_MARRIAGE_CERTIFICATE);

        $analysis = $this->service()->analyse($document);

        $this->assertTrue($analysis->analyzed);
        $this->assertSame('sociedad_conyugal', $analysis->matrimonial_regime);
    }

    /**
     * JSON wrapped in a ```json markdown fence is still parsed correctly.
     */
    #[Test]
    public function it_strips_markdown_fences_from_the_response(): void
    {
        $this->fakeClaude("```json\n{\"document_number\":\"FENCED1\"}\n```");

        $document = $this->makeDocument(DocumentTypeEnum::PASSPORT);

        $analysis = $this->service()->analyse($document);

        $this->assertTrue($analysis->analyzed);
        $this->assertSame('FENCED1', $analysis->document_number);
    }

    /**
     * Non-analysable types are skipped: no API call, no analysis record.
     */
    #[Test]
    public function it_skips_non_analysable_documents(): void
    {
        Http::fake();

        $document = $this->makeDocument(DocumentTypeEnum::OTHER);

        $analysis = $this->service()->analyse($document);

        $this->assertNull($analysis);
        Http::assertNothingSent();
        $this->assertDatabaseMissing('document_analyses', ['document_id' => $document->id]);
    }

    /**
     * A non-JSON response is recorded as a failed analysis instead of throwing.
     */
    #[Test]
    public function it_marks_the_analysis_failed_on_a_non_json_response(): void
    {
        $this->fakeClaude('I could not read this document.');

        $document = $this->makeDocument(DocumentTypeEnum::PASSPORT);

        $analysis = $this->service()->analyse($document);

        $this->assertFalse($analysis->analyzed);
        $this->assertNotNull($analysis->error_message);
        $this->assertNull($analysis->document_number);
        $this->assertDatabaseHas('document_analyses', [
            'document_id' => $document->id,
            'analyzed' => false,
        ]);
    }

    /**
     * A non-200 API response raises a RuntimeException and persists nothing.
     */
    #[Test]
    public function it_throws_when_the_api_returns_an_error(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response('internal error', 500),
        ]);

        $document = $this->makeDocument(DocumentTypeEnum::PASSPORT);

        $this->expectException(\RuntimeException::class);

        try {
            $this->service()->analyse($document);
        } finally {
            $this->assertDatabaseMissing('document_analyses', ['document_id' => $document->id]);
        }
    }

    /**
     * Re-analysing the same document refreshes the existing record rather than duplicating it.
     */
    #[Test]
    public function it_upserts_a_single_record_when_re_analysed(): void
    {
        $document = $this->makeDocument(DocumentTypeEnum::PASSPORT);

        // A sequence returns a different response per call; plain Http::fake() merges
        // stubs (first match wins), so re-faking the same URL would keep returning FIRST.
        Http::fakeSequence('api.anthropic.com/*')
            ->push(['content' => [['type' => 'text', 'text' => json_encode(['document_number' => 'FIRST'])]]], 200)
            ->push(['content' => [['type' => 'text', 'text' => json_encode(['document_number' => 'SECOND'])]]], 200);

        $this->service()->analyse($document);
        $analysis = $this->service()->analyse($document);

        $this->assertSame(1, DocumentAnalysis::where('document_id', $document->id)->count());
        $this->assertSame('SECOND', $analysis->fresh()->document_number);
    }
}
