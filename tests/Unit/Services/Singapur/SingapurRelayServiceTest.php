<?php

namespace Tests\Unit\Services\Singapur;

use App\Models\Document;
use App\Models\Registration;
use App\Services\Singapur\SingapurRelayService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * Unit tests for SingapurRelayService::streamDocument().
 *
 * Uses Http::fake() to intercept the relay HTTP request and returns a real
 * in-memory ZIP so ZipArchive can exercise the actual extraction logic.
 * No database or file system persistence is required.
 */
class SingapurRelayServiceTest extends TestCase
{
    private SingapurRelayService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SingapurRelayService::class);

        config([
            'services.singapur.base_url'     => 'https://relay.test',
            'services.singapur.bearer_token'  => 'test-token',
        ]);
    }

    // -------------------------------------------------------------------------
    // streamDocument()
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_the_content_of_the_requested_zip_entry(): void
    {
        $expectedContent = '%PDF-1.4 fake pdf content';
        $entryPath       = 'KYC/shareholder_1/000001__EMPRESA__naturalTaxCertificate1__tax.pdf';

        Http::fake([
            'relay.test/*' => Http::response(
                $this->makeZipWithEntry($entryPath, $expectedContent),
                200,
            ),
        ]);

        $registration = $this->makeRegistration('000001_EMPRESA SA');
        $document     = $this->makeDocument($entryPath);

        $content = $this->service->streamDocument($registration, $document);

        $this->assertSame($expectedContent, $content);
    }

    #[Test]
    public function it_throws_when_relay_zip_path_is_empty(): void
    {
        Http::fake();

        $registration = $this->makeRegistration('000001_EMPRESA SA');
        $document     = $this->makeDocument(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no relay_zip_path/');

        $this->service->streamDocument($registration, $document);
    }

    #[Test]
    public function it_throws_when_the_entry_is_not_found_in_the_zip(): void
    {
        Http::fake([
            'relay.test/*' => Http::response(
                // ZIP that contains a DIFFERENT entry — requested one is missing.
                $this->makeZipWithEntry('KYC/other/other.pdf', 'other content'),
                200,
            ),
        ]);

        $registration = $this->makeRegistration('000001_EMPRESA SA');
        $document     = $this->makeDocument('KYC/shareholder_1/missing.pdf');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/not found in relay ZIP/");

        $this->service->streamDocument($registration, $document);
    }

    #[Test]
    public function it_throws_when_the_relay_returns_a_non_2xx_response(): void
    {
        Http::fake([
            'relay.test/*' => Http::response('Unauthorized', 401),
        ]);

        $registration = $this->makeRegistration('000001_EMPRESA SA');
        $document     = $this->makeDocument('KYC/shareholder_1/file.pdf');

        $this->expectException(RequestException::class);

        $this->service->streamDocument($registration, $document);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a mock Registration model without hitting the database.
     *
     * @param  string  $folderName  Value for singapur_folder_name.
     * @return Registration
     */
    private function makeRegistration(string $folderName): Registration
    {
        $registration                        = new Registration();
        $registration->singapur_folder_name  = $folderName;

        return $registration;
    }

    /**
     * Build a mock Document model without hitting the database.
     *
     * @param  string|null  $relayZipPath  Value for relay_zip_path.
     * @return Document
     */
    private function makeDocument(?string $relayZipPath): Document
    {
        $document                  = new Document();
        $document->id              = 'doc-test-id';
        $document->relay_zip_path  = $relayZipPath;

        return $document;
    }

    /**
     * Create a real in-memory ZIP archive containing a single entry.
     *
     * ZipArchive requires a file path, so a temp file is used and immediately
     * read back as a binary string so the test has no disk side-effects.
     *
     * @param  string  $entryPath  Path of the entry within the ZIP.
     * @param  string  $content    Content to store in the entry.
     * @return string              Binary ZIP content.
     */
    private function makeZipWithEntry(string $entryPath, string $content): string
    {
        $tmpPath = sys_get_temp_dir() . '/nexum_test_' . uniqid() . '.zip';

        $zip = new ZipArchive();
        $zip->open($tmpPath, ZipArchive::CREATE);
        $zip->addFromString($entryPath, $content);
        $zip->close();

        $binary = file_get_contents($tmpPath);
        @unlink($tmpPath);

        return $binary;
    }
}
