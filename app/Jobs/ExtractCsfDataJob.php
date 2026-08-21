<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Document;
use App\Services\Registration\CsfExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Reads a just-uploaded CSF with Claude vision and fills the registration's RFC + fiscal
 * address in the background (the Claude call is slow, so it never blocks the upload request).
 */
class ExtractCsfDataJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly string $documentId,
    ) {}

    public function handle(CsfExtractionService $service): void
    {
        $document = Document::find($this->documentId);

        if ($document !== null) {
            $service->applyToRegistration($document);
        }
    }
}
