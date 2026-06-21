<?php

namespace App\Services\Singapur;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

/**
 * Downloads bulk document packages from the Singapur relay server.
 *
 * NOTE: As of the base64 inline-delivery architecture, individual document files
 * arrive embedded in the webhook JSON payload and are stored directly in R2 by
 * RegistrationUpsertService. This service is retained only for bulk ZIP downloads
 * (e.g., re-downloading an entire KYC package for batch reprocessing). It is NOT
 * involved in normal webhook processing.
 */
class SingapurRelayService
{
    /**
     * Download the document ZIP from the relay and extract it to a temp directory.
     *
     * The ZIP file is removed immediately after extraction. The caller must call
     * cleanupExtractDirectory() when finished with the extracted files.
     *
     * @param  string  $companyFolderName  Full folder name as registered in the relay (e.g., '000001_NOVA CONSULTORA EMPRESARIAL').
     * @param  string  $documentGroup  Document group to download (defaults to 'KYC').
     * @return string Absolute path to the extracted directory containing the PDFs.
     *
     * @throws RequestException When the relay returns a non-2xx HTTP response.
     * @throws RuntimeException When the ZIP is invalid or cannot be extracted.
     */
    public function downloadDocumentsTo(string $companyFolderName, string $documentGroup = 'KYC'): string
    {
        $zipPath = $this->downloadZip($companyFolderName, $documentGroup);
        $extractDir = $this->extractZip($zipPath);

        // Remove the ZIP immediately — only the extracted PDFs are needed going forward.
        @unlink($zipPath);

        return $extractDir;
    }

    /**
     * Remove a previously extracted document directory and all its contents.
     *
     * Call this in a finally block after processing the downloaded documents
     * to avoid accumulating temp files on the server.
     *
     * @param  string  $extractDir  Absolute path returned by downloadDocumentsTo().
     */
    public function cleanupExtractDirectory(string $extractDir): void
    {
        if (is_dir($extractDir)) {
            $this->deleteDirectory($extractDir);
        }
    }

    /**
     * Send the download request to the relay and persist the binary ZIP response.
     *
     * @param  string  $companyFolderName  Relay folder identifier.
     * @param  string  $documentGroup  Document group identifier.
     * @return string Absolute path to the downloaded ZIP file.
     *
     * @throws RequestException When the relay returns a non-2xx response.
     * @throws RuntimeException When the temp file cannot be written.
     */
    private function downloadZip(string $companyFolderName, string $documentGroup): string
    {
        $baseUrl = config('services.singapur.base_url');
        $bearerToken = config('services.singapur.bearer_token');

        $response = Http::withToken($bearerToken)
            ->timeout(120)
            ->post("{$baseUrl}/api/company-registration/download-folder", [
                'company_folder_name' => $companyFolderName,
                'document_group' => $documentGroup,
            ])
            ->throw();

        $zipPath = sys_get_temp_dir().'/nexum_singapur_'.uniqid().'.zip';
        $written = file_put_contents($zipPath, $response->body());

        if ($written === false) {
            throw new RuntimeException("Failed to write ZIP to temp path: {$zipPath}");
        }

        return $zipPath;
    }

    /**
     * Extract the downloaded ZIP archive to a temporary directory.
     *
     * @param  string  $zipPath  Absolute path to the ZIP file.
     * @return string Absolute path to the extracted directory.
     *
     * @throws RuntimeException When the ZIP cannot be opened or extracted.
     */
    private function extractZip(string $zipPath): string
    {
        $extractDir = sys_get_temp_dir().'/nexum_singapur_'.uniqid().'/';

        if (! mkdir($extractDir, 0o755, true)) {
            throw new RuntimeException("Failed to create extraction directory: {$extractDir}");
        }

        $zip = new ZipArchive;
        $result = $zip->open($zipPath);

        if ($result !== true) {
            throw new RuntimeException("Failed to open ZIP archive (ZipArchive error code: {$result})");
        }

        $zip->extractTo($extractDir);
        $zip->close();

        return $extractDir;
    }

    /**
     * Recursively delete a directory and its contents.
     *
     * @param  string  $dir  Directory to delete.
     */
    private function deleteDirectory(string $dir): void
    {
        $items = glob($dir.'{,.}[!.,!..]*', GLOB_MARK | GLOB_BRACE);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if (is_dir($item)) {
                $this->deleteDirectory($item);
            } else {
                @unlink($item);
            }
        }

        @rmdir($dir);
    }
}
