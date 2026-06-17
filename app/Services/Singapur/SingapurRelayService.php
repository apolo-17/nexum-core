<?php

namespace App\Services\Singapur;

use App\DTOs\SingapurSubmissionDTO;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ZipArchive;

/**
 * Communicates with the Singapur relay server to download and parse submission packages.
 *
 * Handles the HTTP request to the relay's download-folder endpoint, saves the
 * binary ZIP to a temp directory, extracts submission.json, and returns a parsed DTO.
 * Temp directories are always cleaned up after parsing, even on failure.
 */
class SingapurRelayService
{
    /**
     * Create a new relay service instance.
     *
     * @param  SingapurSubmissionParser  $parser  Transforms raw submission data into DTOs.
     */
    public function __construct(
        private readonly SingapurSubmissionParser $parser,
    ) {}

    /**
     * Download the submission ZIP from the relay and return a parsed submission DTO.
     *
     * Makes a POST request to the relay's download-folder endpoint with Bearer auth,
     * saves the binary response to a temp file, extracts submission.json, and delegates
     * parsing to SingapurSubmissionParser.
     *
     * @param  string  $companyFolderName  Full folder name as registered in the relay (e.g., '000001_NOVA CONSULTORA EMPRESARIAL').
     * @param  string  $documentGroup      Document group to download (defaults to 'KYC').
     * @return SingapurSubmissionDTO
     *
     * @throws RequestException  When the relay returns a non-2xx HTTP response.
     * @throws RuntimeException  When the ZIP is invalid or submission.json is missing.
     */
    public function downloadAndParse(string $companyFolderName, string $documentGroup = 'KYC'): SingapurSubmissionDTO
    {
        $zipPath = $this->downloadZip($companyFolderName, $documentGroup);
        $extractDir = $this->extractZip($zipPath);

        try {
            return $this->parseSubmission($extractDir, $companyFolderName, $documentGroup);
        } finally {
            // Always clean up temp files regardless of parsing success or failure.
            $this->cleanupTempFiles($zipPath, $extractDir);
        }
    }

    /**
     * Send the download request to the relay and persist the binary ZIP response.
     *
     * @param  string  $companyFolderName  Relay folder identifier.
     * @param  string  $documentGroup      Document group identifier.
     * @return string  Absolute path to the downloaded ZIP file.
     *
     * @throws RequestException  When the relay returns a non-2xx response.
     * @throws RuntimeException  When the temp file cannot be written.
     */
    private function downloadZip(string $companyFolderName, string $documentGroup): string
    {
        $baseUrl     = config('services.singapur.base_url');
        $bearerToken = config('services.singapur.bearer_token');

        $response = Http::withToken($bearerToken)
            ->timeout(120)
            ->post("{$baseUrl}/api/company-registration/download-folder", [
                'company_folder_name' => $companyFolderName,
                'document_group'      => $documentGroup,
            ])
            ->throw();

        $zipPath = sys_get_temp_dir() . '/nexum_singapur_' . uniqid() . '.zip';
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
     * @return string  Absolute path to the extracted directory.
     *
     * @throws RuntimeException  When the ZIP cannot be opened or extracted.
     */
    private function extractZip(string $zipPath): string
    {
        $extractDir = sys_get_temp_dir() . '/nexum_singapur_' . uniqid() . '/';

        if (! mkdir($extractDir, 0o755, true)) {
            throw new RuntimeException("Failed to create extraction directory: {$extractDir}");
        }

        $zip = new ZipArchive();
        $result = $zip->open($zipPath);

        if ($result !== true) {
            throw new RuntimeException("Failed to open ZIP archive (ZipArchive error code: {$result})");
        }

        $zip->extractTo($extractDir);
        $zip->close();

        return $extractDir;
    }

    /**
     * Locate and parse submission.json from the extracted archive directory.
     *
     * @param  string  $extractDir         Path to extracted ZIP contents.
     * @param  string  $companyFolderName  Used to build the submission path if nested.
     * @param  string  $documentGroup      Document group (used to locate the submission file).
     * @return SingapurSubmissionDTO
     *
     * @throws RuntimeException  When submission.json cannot be found or decoded.
     */
    private function parseSubmission(string $extractDir, string $companyFolderName, string $documentGroup): SingapurSubmissionDTO
    {
        // The relay places submission.json at the root of the extracted ZIP.
        $submissionPath = $extractDir . 'submission.json';

        if (! file_exists($submissionPath)) {
            throw new RuntimeException("submission.json not found in ZIP for folder: {$companyFolderName}");
        }

        $raw = file_get_contents($submissionPath);

        if ($raw === false) {
            throw new RuntimeException("Failed to read submission.json from: {$submissionPath}");
        }

        $data = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('submission.json contains invalid JSON: ' . json_last_error_msg());
        }

        return $this->parser->parse($data);
    }

    /**
     * Remove the temporary ZIP file and extraction directory.
     *
     * Errors during cleanup are swallowed so they do not mask the original exception.
     *
     * @param  string  $zipPath    Absolute path to the ZIP file.
     * @param  string  $extractDir Absolute path to the extracted directory.
     * @return void
     */
    private function cleanupTempFiles(string $zipPath, string $extractDir): void
    {
        if (file_exists($zipPath)) {
            @unlink($zipPath);
        }

        if (is_dir($extractDir)) {
            $this->deleteDirectory($extractDir);
        }
    }

    /**
     * Recursively delete a directory and its contents.
     *
     * @param  string  $dir  Directory to delete.
     * @return void
     */
    private function deleteDirectory(string $dir): void
    {
        $items = glob($dir . '{,.}[!.,!..]*', GLOB_MARK | GLOB_BRACE);

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
