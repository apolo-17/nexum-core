<?php

namespace App\Services\Singapur;

use App\Models\Document;
use App\Services\Document\PdfCompressionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Prepares the file the China/Singapur relay actually pulls for a deliverable.
 *
 * The relay downloads documents straight from R2 (a pre-signed URL), so a huge
 * scanned acta would be handed to China as-is and bounce off its size limit. This
 * service makes a Ghostscript-compressed derivative for oversized PDFs and records
 * its path in Document::relay_storage_path; the relay controller then serves that
 * copy. The original file is never modified. Small or non-PDF deliverables need no
 * derivative and leave relay_storage_path null ("serve the original").
 */
class RelayFileService
{
    /**
     * Folder (relative to the default disk) where compressed derivatives live.
     */
    private const DERIVATIVE_DIR = 'relay-compressed';

    /**
     * @param  PdfCompressionService  $compressor  Ghostscript-backed PDF compressor.
     */
    public function __construct(private readonly PdfCompressionService $compressor) {}

    /**
     * Ensure a relay-servable copy of the document exists under the size limit.
     *
     * No-ops when the document is small enough, is not a PDF, or already has a valid
     * derivative. Failures are logged and swallowed so the alert still goes out with the
     * original file — better to let China decide than to block delivery on a compressor hiccup.
     *
     * @param  Document  $document  The deliverable about to be announced to the relay.
     */
    public function prepare(Document $document): void
    {
        $maxBytes = (int) config('services.singapur.relay_max_bytes');
        $disk = Storage::disk();
        $source = (string) $document->storage_path;

        if ($maxBytes <= 0 || blank($source) || ! $this->isPdf($source)) {
            return;
        }

        // A derivative already recorded and still present is valid — the observer clears it
        // whenever the original file is replaced, so a set value always matches the current file.
        if (filled($document->relay_storage_path) && $disk->exists((string) $document->relay_storage_path)) {
            return;
        }

        try {
            $size = (int) $disk->size($source);
        } catch (\Throwable $exception) {
            Log::warning('RelayFileService: could not size the source document.', [
                'document_id' => $document->id,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        if ($size <= $maxBytes) {
            return; // Original already fits; serve it directly.
        }

        $localIn = tempnam(sys_get_temp_dir(), 'relaysrc_');
        $localOut = tempnam(sys_get_temp_dir(), 'relayout_');

        try {
            // Stream the original down to a local temp file for Ghostscript.
            $stream = $disk->readStream($source);
            if ($stream === null) {
                throw new \RuntimeException('Could not open the source document stream.');
            }
            $handle = fopen($localIn, 'wb');
            stream_copy_to_stream($stream, $handle);
            fclose($handle);
            if (is_resource($stream)) {
                fclose($stream);
            }

            $compressedSize = $this->compressor->compress($localIn, $localOut, $maxBytes);

            // If compression somehow did not shrink the file, keep the original (null derivative).
            if ($compressedSize >= $size) {
                Log::info('RelayFileService: compression did not reduce size; serving original.', [
                    'document_id' => $document->id,
                    'original_bytes' => $size,
                    'compressed_bytes' => $compressedSize,
                ]);

                return;
            }

            $derivativePath = self::DERIVATIVE_DIR.'/'.$document->id.'.pdf';
            $disk->put($derivativePath, fopen($localOut, 'rb'));

            // saveQuietly: recording the derivative must not re-trigger DocumentObserver.
            $document->forceFill(['relay_storage_path' => $derivativePath])->saveQuietly();

            Log::info('RelayFileService: stored compressed derivative for the relay.', [
                'document_id' => $document->id,
                'original_bytes' => $size,
                'compressed_bytes' => $compressedSize,
                'path' => $derivativePath,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('RelayFileService: could not compress the document; serving original.', [
                'document_id' => $document->id,
                'error' => $exception->getMessage(),
            ]);
        } finally {
            @unlink($localIn);
            @unlink($localOut);
        }
    }

    /**
     * Whether a stored path points to a PDF (by extension).
     */
    private function isPdf(string $path): bool
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';
    }
}
