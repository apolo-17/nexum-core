<?php

namespace App\Services\Document;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Compresses PDF files with Ghostscript, downsampling embedded scan images so a
 * 30-37 MB scanned acta drops well under the relay/China size limits.
 *
 * Ghostscript (`gs`) ships on the production image; there is no pure-PHP path that
 * recompresses raster-heavy scans, so this shells out. The service escalates through
 * quality presets, stopping at the first result that fits the target byte budget, and
 * returns the smallest result it produced even when none fit (best effort beats failing).
 */
class PdfCompressionService
{
    /**
     * Ghostscript PDFSETTINGS presets tried in order, coarsest last.
     *
     * /ebook  ≈ 150 dpi — still crisp for on-screen review and archival.
     * /screen ≈ 72 dpi  — last resort for very heavy scans; legible, much smaller.
     *
     * @var list<string>
     */
    private const PRESETS = ['/ebook', '/screen'];

    /**
     * Seconds a single Ghostscript run may take before it is killed.
     */
    private const TIMEOUT_SECONDS = 300;

    /**
     * Compress a PDF, aiming to bring it at or under $targetBytes.
     *
     * @param  string  $inputPath  Absolute path to the source PDF on the local filesystem.
     * @param  string  $outputPath  Absolute path the compressed PDF is written to.
     * @param  int  $targetBytes  Desired maximum size; stops at the first preset that reaches it.
     * @return int The byte size of the written output.
     *
     * @throws RuntimeException When Ghostscript is unavailable or produces no valid output.
     */
    public function compress(string $inputPath, string $outputPath, int $targetBytes): int
    {
        if (! is_file($inputPath)) {
            throw new RuntimeException("PDF to compress not found: {$inputPath}");
        }

        $bestSize = null;

        foreach (self::PRESETS as $preset) {
            $candidate = $outputPath.'.'.trim($preset, '/');

            $result = Process::timeout(self::TIMEOUT_SECONDS)->run([
                'gs',
                '-sDEVICE=pdfwrite',
                '-dCompatibilityLevel=1.4',
                '-dPDFSETTINGS='.$preset,
                '-dNOPAUSE',
                '-dQUIET',
                '-dBATCH',
                '-dDetectDuplicateImages=true',
                '-dCompressFonts=true',
                '-sOutputFile='.$candidate,
                $inputPath,
            ]);

            if (! $result->successful() || ! is_file($candidate)) {
                @unlink($candidate);

                continue;
            }

            $size = (int) filesize($candidate);

            // Keep the smallest valid candidate produced so far.
            if ($bestSize === null || $size < $bestSize) {
                @rename($candidate, $outputPath);
                $bestSize = $size;
            } else {
                @unlink($candidate);
            }

            if ($bestSize <= $targetBytes) {
                break;
            }
        }

        if ($bestSize === null) {
            throw new RuntimeException("Ghostscript produced no output for {$inputPath}.");
        }

        return $bestSize;
    }
}
