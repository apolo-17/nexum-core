<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Serves document files stored in R2 (or local disk) to the admin dashboard.
 *
 * Documents arrive via the Singapur webhook as base64 content and are stored
 * directly in the configured filesystem. This controller retrieves them by
 * storage_path when the admin clicks "Descargar" or opens the preview modal.
 */
class DocumentRelayDownloadController extends Controller
{
    /**
     * Stream a single document from storage as a forced download.
     *
     * Returns a binary download response with Content-Disposition: attachment.
     *
     * @param  Document  $document  The document record to serve.
     */
    public function download(Document $document): StreamedResponse|Response
    {
        return $this->stream($document, attachment: true);
    }

    /**
     * Stream a single document from storage for inline browser display.
     *
     * Returns a response with Content-Disposition: inline so the browser can
     * render it inside an iframe (PDFs and images). Used by the preview modal.
     *
     * @param  Document  $document  The document record to serve.
     */
    public function preview(Document $document): StreamedResponse|Response
    {
        return $this->stream($document, attachment: false);
    }

    /**
     * Core streaming logic shared by download() and preview().
     *
     * Validates that a storage_path exists, reads the file from the configured
     * disk and returns a streamed response. When the storage backend is
     * unavailable (e.g. MinIO not running in local dev, R2 credentials missing),
     * returns a graceful HTML page rather than throwing a 500.
     *
     * @param  Document  $document  The document to stream.
     * @param  bool  $attachment  True to force download; false to display inline.
     */
    private function stream(Document $document, bool $attachment): StreamedResponse|Response
    {
        $this->authorizeAccess();

        if (blank($document->storage_path)) {
            return $this->unavailableResponse(
                'Este documento no tiene archivo almacenado aún.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $exists = Storage::exists($document->storage_path);
        } catch (Throwable) {
            // Storage backend unreachable (MinIO not running, bad S3 credentials, etc.).
            return $this->unavailableResponse(
                'No se pudo conectar al almacenamiento. Verifica que MinIO esté corriendo y que las credenciales S3 estén configuradas.',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        if (! $exists) {
            return $this->unavailableResponse(
                'El archivo no se encontró en el almacenamiento. Es posible que el expediente haya sido creado por el seeder y no tenga archivo real.',
                Response::HTTP_NOT_FOUND,
            );
        }

        $filename = basename($document->storage_path);
        $contentType = Storage::mimeType($document->storage_path) ?? 'application/octet-stream';
        $disposition = $attachment ? 'attachment' : 'inline';

        return Storage::response($document->storage_path, $filename, [
            'Content-Type' => $contentType,
            'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Ensure the current user belongs to the notary team before serving KYC files.
     *
     * These routes sit behind the `auth` middleware, but that alone would let any
     * authenticated session enumerate document IDs and download biometric KYC files.
     * Restrict access to the three notary-team roles; aborts with 403 otherwise.
     *
     * NOTE: this is a role gate, not per-expediente ownership — it matches the panel,
     * which currently shows every expediente to all staff. Tighten to assigned-only
     * (assigned_notario_id / assigned_asistente_id) when the panel is scoped per user.
     */
    private function authorizeAccess(): void
    {
        $user = Auth::user();

        abort_unless(
            $user !== null && $user->hasAnyRole(['super_admin', 'notario', 'asistente_notario']),
            Response::HTTP_FORBIDDEN,
            'No tienes permiso para acceder a este documento.',
        );
    }

    /**
     * Return a plain HTML response suitable for rendering inside an iframe.
     *
     * The message is displayed as centered text so the preview modal shows a
     * human-readable explanation instead of a raw error page.
     *
     * @param  string  $message  Human-readable reason the file is unavailable.
     * @param  int  $statusCode  HTTP status code to set on the response.
     */
    private function unavailableResponse(string $message, int $statusCode): Response
    {
        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <style>
                body {
                    font-family: system-ui, sans-serif;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    height: 100vh;
                    margin: 0;
                    background: #f9fafb;
                    color: #6b7280;
                }
                .box {
                    text-align: center;
                    padding: 2rem;
                    max-width: 420px;
                }
                .icon { font-size: 3rem; margin-bottom: 1rem; }
                p { font-size: 0.95rem; line-height: 1.6; }
            </style>
        </head>
        <body>
            <div class="box">
                <div class="icon">📄</div>
                <p>{$message}</p>
            </div>
        </body>
        </html>
        HTML;

        return response($html, $statusCode)->header('Content-Type', 'text/html');
    }
}
