<?php

namespace Tests\Unit\Services\Singapur;

use App\Enums\DocumentTypeEnum;
use App\Models\Document;
use App\Services\Document\PdfCompressionService;
use App\Services\Singapur\RelayFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RelayFileService builds a compressed derivative only for oversized scanned PDFs,
 * leaving small or non-PDF deliverables to be served straight from the original file.
 */
class RelayFileServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
        // Tiny threshold so a short fixture string counts as "oversized".
        config()->set('services.singapur.relay_max_bytes', 10);
    }

    /**
     * A compressor stub that writes a fixed small output and reports its size,
     * standing in for Ghostscript (absent in the test environment).
     */
    private function stubCompressor(string $payload = 'small'): PdfCompressionService
    {
        return new class($payload) extends PdfCompressionService
        {
            public function __construct(private string $payload) {}

            public function compress(string $inputPath, string $outputPath, int $targetBytes): int
            {
                file_put_contents($outputPath, $this->payload);

                return strlen($this->payload);
            }
        };
    }

    private function document(string $path, string $contents): Document
    {
        Storage::put($path, $contents);

        return Document::factory()->create([
            'type' => DocumentTypeEnum::ACTA_PROTOCOLIZADA,
            'storage_path' => $path,
            'relay_storage_path' => null,
        ]);
    }

    #[Test]
    public function it_compresses_an_oversized_pdf_and_records_the_derivative(): void
    {
        $document = $this->document('documents/acta.pdf', str_repeat('X', 5000));

        (new RelayFileService($this->stubCompressor()))->prepare($document);

        $derivative = $document->fresh()->relay_storage_path;
        $this->assertNotNull($derivative, 'An oversized PDF must get a compressed derivative.');
        $this->assertTrue(Storage::exists($derivative));
        // Original is untouched.
        $this->assertSame(5000, strlen(Storage::get('documents/acta.pdf')));
    }

    #[Test]
    public function it_leaves_a_small_pdf_untouched(): void
    {
        config()->set('services.singapur.relay_max_bytes', 22 * 1024 * 1024);
        $document = $this->document('documents/small.pdf', 'tiny');

        (new RelayFileService($this->stubCompressor()))->prepare($document);

        $this->assertNull($document->fresh()->relay_storage_path);
    }

    #[Test]
    public function it_ignores_non_pdf_deliverables(): void
    {
        $document = $this->document('documents/efirma.zip', str_repeat('X', 5000));

        (new RelayFileService($this->stubCompressor()))->prepare($document);

        $this->assertNull($document->fresh()->relay_storage_path);
    }

    #[Test]
    public function it_is_a_noop_when_a_valid_derivative_already_exists(): void
    {
        $document = $this->document('documents/acta.pdf', str_repeat('X', 5000));
        Storage::put('relay-compressed/'.$document->id.'.pdf', 'already');
        $document->forceFill(['relay_storage_path' => 'relay-compressed/'.$document->id.'.pdf'])->saveQuietly();

        // A compressor that would explode if called proves prepare() short-circuits.
        $exploding = new class extends PdfCompressionService
        {
            public function compress(string $inputPath, string $outputPath, int $targetBytes): int
            {
                throw new \RuntimeException('compress must not be called');
            }
        };

        (new RelayFileService($exploding))->prepare($document);

        $this->assertSame('already', Storage::get('relay-compressed/'.$document->id.'.pdf'));
    }

    #[Test]
    public function it_keeps_the_original_when_compression_does_not_shrink_it(): void
    {
        $document = $this->document('documents/acta.pdf', str_repeat('X', 5000));

        // Stub "compresses" to something larger than the original → derivative rejected.
        $worse = $this->stubCompressor(str_repeat('Y', 9000));

        (new RelayFileService($worse))->prepare($document);

        $this->assertNull($document->fresh()->relay_storage_path);
    }
}
