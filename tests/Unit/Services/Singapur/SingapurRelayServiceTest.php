<?php

namespace Tests\Unit\Services\Singapur;

use App\Services\Singapur\SingapurRelayService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * Unit tests for SingapurRelayService::downloadDocumentsTo().
 *
 * Uses Http::fake() to intercept the relay HTTP request and returns a real
 * in-memory ZIP so ZipArchive can exercise the actual extraction logic.
 *
 * NOTE: streamDocument() was removed when documents moved to inline base64 delivery
 * via the webhook payload. Individual files are now stored by RegistrationUpsertService.
 */
class SingapurRelayServiceTest extends TestCase
{
    private SingapurRelayService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SingapurRelayService::class);

        config([
            'services.singapur.base_url' => 'https://relay.test',
            'services.singapur.bearer_token' => 'test-token',
        ]);
    }

    // -------------------------------------------------------------------------
    // downloadDocumentsTo()
    // -------------------------------------------------------------------------

    #[Test]
    public function it_returns_an_extracted_directory_path_containing_the_downloaded_files(): void
    {
        $entryPath = 'KYC/shareholder_1/000001__naturalTaxCertificate1__tax.pdf';
        $fileContent = '%PDF-1.4 fake pdf content';

        Http::fake([
            'relay.test/*' => Http::response(
                $this->makeZipWithEntry($entryPath, $fileContent),
                200,
            ),
        ]);

        $extractDir = $this->service->downloadDocumentsTo('000001_EMPRESA SA');

        $this->assertDirectoryExists($extractDir);
        $this->assertFileExists($extractDir.$entryPath);
        $this->assertSame($fileContent, file_get_contents($extractDir.$entryPath));

        // Cleanup after assertion to avoid leaving temp dirs on disk.
        $this->service->cleanupExtractDirectory($extractDir);
    }

    #[Test]
    public function it_throws_when_the_relay_returns_a_non_2xx_response(): void
    {
        Http::fake([
            'relay.test/*' => Http::response('Unauthorized', 401),
        ]);

        $this->expectException(RequestException::class);

        $this->service->downloadDocumentsTo('000001_EMPRESA SA');
    }

    #[Test]
    public function cleanup_removes_the_extracted_directory(): void
    {
        Http::fake([
            'relay.test/*' => Http::response(
                $this->makeZipWithEntry('KYC/file.pdf', 'content'),
                200,
            ),
        ]);

        $extractDir = $this->service->downloadDocumentsTo('000001_EMPRESA SA');

        $this->assertDirectoryExists($extractDir);

        $this->service->cleanupExtractDirectory($extractDir);

        $this->assertDirectoryDoesNotExist($extractDir);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create a real in-memory ZIP archive containing a single entry.
     *
     * ZipArchive requires a file path, so a temp file is used and immediately
     * read back as a binary string so the test has no disk side-effects.
     *
     * @param  string  $entryPath  Path of the entry within the ZIP.
     * @param  string  $content  Content to store in the entry.
     * @return string Binary ZIP content.
     */
    private function makeZipWithEntry(string $entryPath, string $content): string
    {
        $tmpPath = sys_get_temp_dir().'/nexum_test_'.uniqid().'.zip';

        $zip = new ZipArchive;
        $zip->open($tmpPath, ZipArchive::CREATE);
        $zip->addFromString($entryPath, $content);
        $zip->close();

        $binary = file_get_contents($tmpPath);
        @unlink($tmpPath);

        return $binary;
    }
}
