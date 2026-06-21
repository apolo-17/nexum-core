<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves document files stored in R2 (or local disk) to the admin dashboard.
 *
 * Documents arrive via the Singapur webhook as base64 content and are stored
 * directly in the configured filesystem. This controller retrieves them by
 * storage_path when the admin clicks "Descargar" in the Filament dashboard.
 */
class DocumentRelayDownloadController extends Controller
{
    /**
     * Stream a single document from storage to the browser.
     *
     * Reads the file from the storage path saved at webhook time and returns
     * it as a binary download response. Returns 422 when no storage path exists.
     *
     * @param  Document  $document  The document record to serve.
     *
     * @throws \RuntimeException When storage_path is missing or the file cannot be read.
     */
    public function download(Document $document): StreamedResponse
    {
        abort_if(
            blank($document->storage_path),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Este documento no tiene archivo almacenado aún.'
        );

        abort_unless(
            Storage::exists($document->storage_path),
            Response::HTTP_NOT_FOUND,
            'El archivo no se encontró en el almacenamiento.'
        );

        $filename = basename($document->storage_path);
        $contentType = Storage::mimeType($document->storage_path) ?? 'application/octet-stream';

        return Storage::download($document->storage_path, $filename, [
            'Content-Type' => $contentType,
        ]);
    }
}
