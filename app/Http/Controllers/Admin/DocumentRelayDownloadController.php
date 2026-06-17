<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\Singapur\SingapurRelayService;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves document files fetched on-demand from the Singapur relay ZIP archive.
 *
 * The relay stores all KYC files inside a ZIP per company folder. Rather than
 * downloading and storing the full archive at webhook time, this controller
 * fetches only the requested file when the admin clicks "Descargar del relay"
 * in the Filament dashboard. The ZIP is downloaded, the single entry extracted,
 * and the ZIP is deleted — all within this request.
 */
class DocumentRelayDownloadController extends Controller
{
    /**
     * @param  SingapurRelayService  $relayService  Handles ZIP download and extraction.
     */
    public function __construct(
        private readonly SingapurRelayService $relayService,
    ) {}

    /**
     * Stream a single document from the Singapur relay ZIP to the browser.
     *
     * Resolves the parent Registration via the Document relationship, downloads
     * the relay ZIP, extracts the requested entry, and returns it as a binary
     * download response. The ZIP is removed immediately after extraction.
     *
     * @param  Document  $document  The document record to fetch from the relay.
     * @return StreamedResponse
     *
     * @throws \RuntimeException  When relay_zip_path is missing or the entry is not in the archive.
     */
    public function download(Document $document): StreamedResponse
    {
        abort_if(
            blank($document->relay_zip_path),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Este documento no tiene ruta en el relay.'
        );

        $registration = $document->registration;

        abort_if(
            blank($registration?->singapur_folder_name),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'El expediente no tiene carpeta registrada en el relay.'
        );

        $content     = $this->relayService->streamDocument($registration, $document);
        $filename    = basename($document->relay_zip_path);
        $contentType = str_ends_with($filename, '.pdf') ? 'application/pdf' : 'application/octet-stream';

        return response()->streamDownload(
            fn () => print($content),
            $filename,
            ['Content-Type' => $contentType],
        );
    }
}
