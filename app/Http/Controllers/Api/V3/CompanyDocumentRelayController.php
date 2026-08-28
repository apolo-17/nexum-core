<?php

namespace App\Http\Controllers\Api\V3;

use App\Enums\DocumentTypeEnum;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Registration;
use App\Notifications\ChinaDeliveryNotification;
use App\Services\Singapur\RelayDocumentAlertService;
use App\Services\Singapur\RelayMessageAi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Serves finished company documents to the China/Singapur relay via a pull model.
 *
 * Instead of pushing potentially heavy files (acta, e.firma ZIP, CSF, ...) to the
 * relay, Nexum exposes two read-only endpoints guarded by the same shared secret
 * the relay already sends on the webhook (X-Nexum-Secret):
 *
 *   - index(): list which company documents are ready for a registration.
 *   - show():  return a short-lived pre-signed R2 URL so the relay downloads the
 *              file directly from storage — the bytes never travel through Nexum.
 *
 * Document types use the relay's own slugs (incorporation_deed, e_firma, ...),
 * mapped here to Nexum's internal DocumentTypeEnum with a priority fallback.
 */
class CompanyDocumentRelayController extends Controller
{
    /**
     * Minutes a generated pre-signed download URL stays valid.
     */
    private const URL_TTL_MINUTES = 60;

    /**
     * Map each relay document slug to Nexum DocumentTypeEnum candidates in
     * priority order. The first candidate with a stored document wins, so the
     * signed acta is preferred over the plain render when both exist.
     *
     * @var array<string, list<DocumentTypeEnum>>
     */
    private const RELAY_TYPE_MAP = [
        // El incorporation_deed que China necesita es la ESCRITURA PROTOCOLIZADA — debe ir
        // PRIMERO para que el relay jale exactamente lo que anunciamos (RelayDocumentAlertService
        // avisa solo por acta_protocolizada). El resto queda como respaldo por compatibilidad.
        'incorporation_deed' => [
            DocumentTypeEnum::ACTA_PROTOCOLIZADA,
            DocumentTypeEnum::ACTA_SIGNED,
            DocumentTypeEnum::ACTA_FINAL,
            DocumentTypeEnum::INCORPORATION_DEED,
        ],
        'rpc_registration_receipt' => [DocumentTypeEnum::RPP_REGISTRATION],
        'company_proof_of_address' => [DocumentTypeEnum::PROOF_OF_ADDRESS_MX],
        'tax_status_certificate' => [DocumentTypeEnum::CSF],
        'e_firma' => [DocumentTypeEnum::EFIRMA],
    ];

    /**
     * List the company documents that are ready for a registration.
     *
     * Returns one entry per relay document type that already has a stored file,
     * so the relay knows what it can pull without downloading anything.
     *
     * @param  Request  $request  Request carrying the X-Nexum-Secret header.
     * @param  string  $singapurClientCode  Six-digit registration number (natural key).
     * @return JsonResponse 200 with the available documents, 401/404 otherwise.
     */
    public function index(Request $request, string $singapurClientCode): JsonResponse
    {
        $this->assertRelayToken($request);

        $registration = $this->findRegistration($singapurClientCode);

        if ($registration === null) {
            return response()->json(['error' => 'Registration not found'], Response::HTTP_NOT_FOUND);
        }

        $documents = [];

        foreach (self::RELAY_TYPE_MAP as $slug => $types) {
            $document = $this->resolveDocument($registration, $types);

            if ($document !== null && filled($document->storage_path)) {
                $documents[] = [
                    'document_type' => $slug,
                    'filename' => $this->filenameFor($document),
                ];
            }
        }

        return response()->json([
            'registration_number' => $registration->singapur_client_code,
            'documents' => $documents,
        ], Response::HTTP_OK);
    }

    /**
     * Issue a short-lived pre-signed download URL for a single company document.
     *
     * The relay follows the returned URL to pull the file straight from R2, so
     * heavy documents never stream through Nexum's application server.
     *
     * @param  Request  $request  Request carrying the X-Nexum-Secret header.
     * @param  string  $singapurClientCode  Six-digit registration number (natural key).
     * @param  string  $documentType  One of the relay document slugs (RELAY_TYPE_MAP keys).
     * @return JsonResponse 200 with the signed URL, or 401/404/503 on failure.
     */
    public function show(Request $request, string $singapurClientCode, string $documentType): JsonResponse
    {
        $this->assertRelayToken($request);

        if (! array_key_exists($documentType, self::RELAY_TYPE_MAP)) {
            return response()->json(['error' => 'Unsupported document type'], Response::HTTP_NOT_FOUND);
        }

        $registration = $this->findRegistration($singapurClientCode);

        if ($registration === null) {
            return response()->json(['error' => 'Registration not found'], Response::HTTP_NOT_FOUND);
        }

        $document = $this->resolveDocument($registration, self::RELAY_TYPE_MAP[$documentType]);

        if ($document === null || blank($document->storage_path)) {
            return response()->json(['error' => 'Document not available yet'], Response::HTTP_NOT_FOUND);
        }

        $expiresAt = now()->addMinutes(self::URL_TTL_MINUTES);

        try {
            $url = Storage::temporaryUrl($this->relayPath($document), $expiresAt);
        } catch (Throwable) {
            // R2/S3 misconfigured or the driver cannot sign URLs (e.g. local dev).
            return response()->json(
                ['error' => 'Storage backend cannot issue a download link'],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return response()->json([
            'document_type' => $documentType,
            'registration_number' => $registration->singapur_client_code,
            'filename' => $this->filenameFor($document),
            'url' => $url,
            'expires_at' => $expiresAt->toIso8601String(),
        ], Response::HTTP_OK);
    }

    /**
     * China rejects a delivered document (e.g. it was the wrong file), with a reason.
     *
     * Token-guarded (X-Nexum-Secret). Marks the document as rejected, translates the reason to
     * Spanish and alerts the super admins so the operator can replace it. Body: {reason}.
     *
     * @return JsonResponse 200 when flagged, 401/404 otherwise.
     */
    public function reject(Request $request, string $singapurClientCode, string $documentType): JsonResponse
    {
        $this->assertRelayToken($request);

        if (! array_key_exists($documentType, self::RELAY_TYPE_MAP)) {
            return response()->json(['error' => 'Unsupported document type'], Response::HTTP_NOT_FOUND);
        }

        $registration = $this->findRegistration($singapurClientCode);
        if ($registration === null) {
            return response()->json(['error' => 'Registration not found'], Response::HTTP_NOT_FOUND);
        }

        $document = $this->resolveDocument($registration, self::RELAY_TYPE_MAP[$documentType]);
        if ($document === null) {
            return response()->json(['error' => 'Document not found'], Response::HTTP_NOT_FOUND);
        }

        $rawReason = trim((string) $request->input('reason', ''));
        $reason = $rawReason !== ''
            ? app(RelayMessageAi::class)->translateRejection($document, $rawReason)
            : 'China rechazó el documento (sin motivo especificado).';

        // Marca el rechazo. No cambia storage_path, así que el observer no reenvía nada; el
        // reenvío ocurre cuando el operador sube el documento correcto (reemplazo).
        $document->forceFill([
            'relay_rejected_at' => now(),
            'relay_rejection_reason' => $reason,
        ])->save();

        app(RelayDocumentAlertService::class)->notifySuperAdmins(
            ChinaDeliveryNotification::for($document, 'rejected', $reason),
        );

        return response()->json(['ok' => true], Response::HTTP_OK);
    }

    /**
     * Reject the request unless it carries the valid shared relay secret.
     *
     * Reuses the Singapur webhook secret (X-Nexum-Secret) so the relay needs no
     * new credential. Uses a timing-safe comparison and aborts with 401 when the
     * secret is missing or wrong.
     *
     * @param  Request  $request  Incoming request.
     */
    private function assertRelayToken(Request $request): void
    {
        $secret = config('services.singapur.webhook_secret');
        $provided = (string) $request->header('X-Nexum-Secret');

        abort_unless(
            is_string($secret) && $secret !== '' && hash_equals($secret, $provided),
            Response::HTTP_UNAUTHORIZED,
            'Unauthorized',
        );
    }

    /**
     * Resolve a registration by its six-digit Singapur client code.
     *
     * @param  string  $singapurClientCode  The natural key from the relay.
     */
    private function findRegistration(string $singapurClientCode): ?Registration
    {
        return Registration::where('singapur_client_code', $singapurClientCode)->first();
    }

    /**
     * Return the latest stored document matching the first available type.
     *
     * @param  Registration  $registration  The expedient to search.
     * @param  list<DocumentTypeEnum>  $types  Candidate types in priority order.
     */
    private function resolveDocument(Registration $registration, array $types): ?Document
    {
        foreach ($types as $type) {
            $document = $registration->documents()
                ->where('type', $type)
                ->latest()
                ->first();

            if ($document !== null) {
                return $document;
            }
        }

        return null;
    }

    /**
     * Derive a human-readable filename for a document.
     *
     * Prefers the stored name and falls back to the storage path basename.
     *
     * @param  Document  $document  The document to name.
     */
    private function filenameFor(Document $document): string
    {
        return $document->name ?: basename((string) $document->storage_path);
    }

    /**
     * The storage path the relay should pull: the compressed derivative when one
     * exists (oversized scanned PDFs), otherwise the original file.
     *
     * @param  Document  $document  The document being served.
     */
    private function relayPath(Document $document): string
    {
        return filled($document->relay_storage_path)
            ? (string) $document->relay_storage_path
            : (string) $document->storage_path;
    }
}
