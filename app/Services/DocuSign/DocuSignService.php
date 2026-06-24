<?php

namespace App\Services\DocuSign;

use App\Enums\DocumentTypeEnum;
use App\Models\Document;
use App\Models\Registration;
use DocuSign\eSign\Api\EnvelopesApi;
use DocuSign\eSign\Client\ApiClient;
use DocuSign\eSign\Client\ApiException;
use DocuSign\eSign\Model\Document as DocuSignDocument;
use DocuSign\eSign\Model\EnvelopeDefinition;
use DocuSign\eSign\Model\EnvelopeSummary;
use DocuSign\eSign\Model\Recipients;
use DocuSign\eSign\Model\RecipientViewRequest;
use DocuSign\eSign\Model\Signer;
use DocuSign\eSign\Model\SignHere;
use DocuSign\eSign\Model\Tabs;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Handles DocuSign envelope creation and signing URLs for the partner_signature stage.
 *
 * Adapted from the Tally implementation. Key differences:
 * - Signers are Registration shareholders (not Company partners).
 * - The document sent is a .docx (not a PDF) — DocuSign converts it automatically.
 * - Signature tab positioning uses ASCII anchor strings (-FIRMA1, -FIRMA2, etc.)
 *   stored in ACTA_FINAL.template_data.anchor_map, so no page-number coordinates needed.
 * - JWT auth flow is identical to Tally.
 * - Authentication is lazy: the HTTP call to DocuSign happens only on the first API call,
 *   not on instantiation. This allows safe use of resolve(DocuSignService::class) in
 *   Filament header actions without hitting DocuSign on every page load.
 *
 * Typical flow:
 *   1. sendActaForSignature($registration) — creates the envelope and returns envelope_id.
 *   2. generateSigningUrl($envelopeId, $recipientId, ...) — embeds per-signer signing URL.
 *   3. verifyHmac($request) — validates DocuSign webhook callbacks.
 */
class DocuSignService
{
    /**
     * Flag tracking whether the API client has been authenticated.
     * Authentication is performed lazily on the first real API call.
     */
    private bool $authenticated = false;

    /**
     * @param  ApiClient  $apiClient  Pre-configured DocuSign HTTP client (unauthenticated at construction time).
     */
    public function __construct(private ApiClient $apiClient) {}

    /**
     * Ensure the API client is authenticated before making any DocuSign API call.
     *
     * Performs the JWT grant exchange on the first call and caches the result
     * in the client's config headers for all subsequent calls within the same request.
     *
     *
     * @throws \RuntimeException When the JWT exchange or userinfo call fails.
     */
    private function ensureAuthenticated(): void
    {
        if ($this->authenticated) {
            return;
        }

        $loginDetails = $this->getDocusignLoginDetails();

        $this->apiClient->getConfig()->setHost($loginDetails['uri'].'/restapi');
        $this->apiClient->getConfig()->addDefaultHeader('Authorization', "Bearer {$loginDetails['access_token']}");
        $this->apiClient->getConfig()->addDefaultHeader('Content-Type', 'application/json');

        $this->authenticated = true;
    }

    /**
     * Send the ACTA_FINAL .docx to all shareholders for electronic signature.
     *
     * Reads the ACTA_FINAL document from R2, creates a DocuSign envelope with one
     * SignHere tab per shareholder positioned via anchor strings (-FIRMA1, -FIRMA2...),
     * and stores the envelope_id in the document's template_data.
     *
     * @param  Registration  $registration  The expedient in the partner_signature stage.
     * @return string The DocuSign envelope_id on success.
     *
     * @throws \RuntimeException When ACTA_FINAL is missing, has no anchor map, or the envelope creation fails.
     */
    public function sendActaForSignature(Registration $registration): string
    {
        $this->ensureAuthenticated();

        $actaFinal = $registration->documents()
            ->where('type', DocumentTypeEnum::ACTA_FINAL)
            ->latest()
            ->first();

        if ($actaFinal === null) {
            throw new \RuntimeException('No ACTA_FINAL found. Generate the .docx before sending for signature.');
        }

        $anchorMap = $actaFinal->template_data['anchor_map'] ?? [];

        if (empty($anchorMap)) {
            throw new \RuntimeException('ACTA_FINAL has no anchor_map in template_data. Regenerate the .docx first.');
        }

        // Check if an envelope already exists — avoid duplicates.
        $existingEnvelopeId = $actaFinal->template_data['sign_status']['envelope_id'] ?? null;

        if ($existingEnvelopeId !== null) {
            Log::info('DocuSignService: envelope already exists, reusing', [
                'registration_id' => $registration->id,
                'envelope_id' => $existingEnvelopeId,
            ]);

            return $existingEnvelopeId;
        }

        // Download the .docx from R2 for DocuSign.
        $docxContent = Storage::disk('s3')->get($actaFinal->storage_path);

        if ($docxContent === null) {
            throw new \RuntimeException("ACTA_FINAL file not found in storage: {$actaFinal->storage_path}");
        }

        // Build the DocuSign Document (docx — DocuSign auto-converts to PDF for display).
        $docuSignDocument = new DocuSignDocument([
            'document_base64' => base64_encode($docxContent),
            'name' => "Acta constitutiva — {$registration->singapur_client_code}",
            'file_extension' => 'docx',
            'document_id' => '1',
        ]);

        // Build one Signer per entry in the anchor_map.
        $signers = [];
        $recipientIndex = 1;

        foreach ($anchorMap as $key => $signerInfo) {
            $signers[] = $this->buildSigner(
                email: $signerInfo['email'],
                name: $signerInfo['nombre'],
                anchorString: $signerInfo['anchor'],
                recipientId: (string) $recipientIndex,
            );

            $recipientIndex++;
        }

        $recipients = new Recipients;
        $recipients->setSigners($signers);

        $envelopeDefinition = new EnvelopeDefinition;
        $envelopeDefinition->setEmailSubject("Firma requerida — Acta constitutiva {$registration->singapur_client_code}");
        $envelopeDefinition->setDocuments([$docuSignDocument]);
        $envelopeDefinition->setRecipients($recipients);
        $envelopeDefinition->setStatus('sent');

        try {
            $envelopeApi = new EnvelopesApi($this->apiClient);
            /** @var EnvelopeSummary $envelope */
            $envelope = $envelopeApi->createEnvelope(
                config('services.docusign.account_id'),
                $envelopeDefinition,
            );

            $envelopeId = $envelope->getEnvelopeId();

            // Persist the envelope_id and initial signer status in the document.
            $templateData = $actaFinal->template_data ?? [];
            $templateData['sign_status'] = [
                'envelope_id' => $envelopeId,
                'status' => 'sent',
                'sent_at' => now()->toIso8601String(),
                'signer_status' => collect($anchorMap)->mapWithKeys(fn (array $info, string $key) => [
                    $key => ['nombre' => $info['nombre'], 'email' => $info['email'], 'status' => 'sent'],
                ])->all(),
            ];

            $actaFinal->update(['template_data' => $templateData]);

            Log::info('DocuSignService: envelope created', [
                'registration_id' => $registration->id,
                'envelope_id' => $envelopeId,
            ]);

            return $envelopeId;

        } catch (ApiException $e) {
            Log::error('DocuSignService: failed to create envelope', [
                'registration_id' => $registration->id,
                'error' => $e->getMessage(),
                'response_body' => $e->getResponseBody(),
            ]);

            throw new \RuntimeException('DocuSign envelope creation failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Generate an embedded signing URL for a specific recipient.
     *
     * Called from the Filament action to give each partner a direct link to sign.
     * The clientUserId must match the recipientId set in buildSigner().
     *
     * @param  string  $envelopeId  DocuSign envelope ID from sendActaForSignature().
     * @param  string  $recipientId  Numeric string ("1", "2", ...) matching clientUserId.
     * @param  string  $recipientEmail  Signer's email address.
     * @param  string  $recipientName  Signer's full name.
     * @param  string  $returnUrl  Where DocuSign redirects after signing.
     * @return string|null Signing URL or null on error.
     */
    public function generateSigningUrl(
        string $envelopeId,
        string $recipientId,
        string $recipientEmail,
        string $recipientName,
        string $returnUrl,
    ): ?string {
        $this->ensureAuthenticated();

        try {
            $envelopeApi = new EnvelopesApi($this->apiClient);

            $viewRequest = new RecipientViewRequest;
            $viewRequest->setReturnUrl($returnUrl);
            $viewRequest->setAuthenticationMethod('email');
            $viewRequest->setEmail($recipientEmail);
            $viewRequest->setUserName($recipientName);
            $viewRequest->setRecipientId($recipientId);
            $viewRequest->setClientUserId($recipientId);
            $viewRequest->setFrameAncestors([config('app.url')]);
            $viewRequest->setMessageOrigins([config('app.url')]);

            $viewUrl = $envelopeApi->createRecipientView(
                config('services.docusign.account_id'),
                $envelopeId,
                $viewRequest,
            );

            return $viewUrl->getUrl() ?? null;

        } catch (ApiException $e) {
            Log::error('DocuSignService: failed to generate signing URL', [
                'envelope_id' => $envelopeId,
                'recipient_id' => $recipientId,
                'error' => $e->getMessage(),
                'response_body' => $e->getResponseBody(),
            ]);

            return null;
        }
    }

    /**
     * Retrieve the current envelope status and per-recipient status from DocuSign.
     *
     * @param  string  $envelopeId  The envelope to query.
     * @return array{status: string, signer_status: array<int, array{name: string, email: string, status: string}>}
     *
     * @throws ApiException When the DocuSign API call fails.
     */
    public function getEnvelopeStatus(string $envelopeId): array
    {
        $this->ensureAuthenticated();

        $envelopeApi = new EnvelopesApi($this->apiClient);

        $envelope = $envelopeApi->getEnvelope(config('services.docusign.account_id'), $envelopeId);
        $recipients = $envelopeApi->listRecipients(config('services.docusign.account_id'), $envelopeId);

        $signerStatus = array_map(
            fn (array $signer) => [
                'name' => $signer['name'],
                'email' => $signer['email'],
                'status' => $signer['status'],
            ],
            $recipients['signers'] ?? [],
        );

        return [
            'status' => $envelope['status'],
            'signer_status' => $signerStatus,
        ];
    }

    /**
     * Download the signed document PDF from DocuSign and store it in R2 as ACTA_SIGNED.
     *
     * Called from the DocuSign webhook handler when an envelope reaches "completed" status.
     *
     * @param  Registration  $registration  The expedient whose envelope completed.
     * @param  string  $envelopeId  The completed DocuSign envelope ID.
     * @return Document The created ACTA_SIGNED Document record.
     *
     * @throws ApiException When the DocuSign download fails.
     * @throws \RuntimeException When storage write fails.
     */
    public function downloadSignedDocument(Registration $registration, string $envelopeId): Document
    {
        $this->ensureAuthenticated();

        $envelopeApi = new EnvelopesApi($this->apiClient);

        // DocuSign returns a combined PDF with all documents.
        $pdfContent = $envelopeApi->getDocument(
            config('services.docusign.account_id'),
            $envelopeId,
            'combined',
        );

        $filename = "acta_{$registration->singapur_client_code}_signed.pdf";
        $storagePath = "documents/{$registration->id}/acta_signed/{$filename}";

        Storage::disk('s3')->put($storagePath, $pdfContent);

        $actaSigned = Document::updateOrCreate(
            [
                'registration_id' => $registration->id,
                'type' => DocumentTypeEnum::ACTA_SIGNED,
            ],
            [
                'name' => $filename,
                'storage_path' => $storagePath,
                'stage' => $registration->stage,
                'template_data' => [
                    'envelope_id' => $envelopeId,
                    'signed_at' => now()->toIso8601String(),
                ],
            ],
        );

        Log::info('DocuSignService: signed document saved', [
            'registration_id' => $registration->id,
            'document_id' => $actaSigned->id,
            'storage_path' => $storagePath,
        ]);

        return $actaSigned;
    }

    /**
     * Verify the HMAC-SHA256 signature on a DocuSign webhook request.
     *
     * DocuSign sends "X-DocuSign-Signature-1" as base64(hex2bin(hmac)).
     * Returns true only if the computed and provided values match.
     *
     * @param  string  $payload  Raw request body string.
     * @param  string  $provided  Value of the X-DocuSign-Signature-1 header.
     * @return bool Whether the signature is valid.
     */
    public function verifyHmac(string $payload, string $provided): bool
    {
        $secret = (string) config('services.docusign.secret_hmac');
        $computed = base64_encode(hex2bin(hash_hmac('sha256', $payload, $secret, false)));

        $valid = hash_equals($provided, $computed);

        if (! $valid) {
            Log::warning('DocuSignService: HMAC verification failed', [
                'provided' => $provided,
                'computed' => $computed,
            ]);
        }

        return $valid;
    }

    /**
     * Obtain a fresh access token and base URI via DocuSign JWT grant.
     *
     * Encodes the JWT using the RSA private key from config, POSTs to DocuSign's
     * oauth/token endpoint, then fetches the base URI from oauth/userinfo.
     *
     * @return array{access_token: string, uri: string}
     *
     * @throws \RuntimeException When the token exchange or userinfo call fails.
     */
    public function getDocusignLoginDetails(): array
    {
        $token = $this->createDocusignJWT();

        if (! isset($token['access_token'])) {
            throw new \RuntimeException('DocuSign JWT exchange failed: '.json_encode($token));
        }

        $userInfo = Http::withHeaders(['Authorization' => 'Bearer '.$token['access_token']])
            ->get('https://'.config('services.docusign.base_url').'/oauth/userinfo')
            ->json();

        $baseUri = $userInfo['accounts'][0]['base_uri']
            ?? throw new \RuntimeException('DocuSign userinfo did not return a base_uri.');

        return [
            'access_token' => $token['access_token'],
            'uri' => $baseUri,
        ];
    }

    /**
     * Create and sign the JWT assertion for the DocuSign JWT grant flow.
     *
     * @return array{access_token: string, token_type: string, expires_in: int}
     */
    private function createDocusignJWT(): array
    {
        $currentTime = time();

        $claims = [
            'iss' => config('services.docusign.integration_key'),
            'sub' => config('services.docusign.user_id'),
            'aud' => config('services.docusign.base_url'),
            'iat' => $currentTime,
            'exp' => $currentTime + 3600,
            'scope' => 'signature impersonation',
        ];

        $privateKey = str_replace('\\n', "\n", (string) config('services.docusign.rsa_private_key'));

        $assertion = JWT::encode($claims, $privateKey, 'RS256', null, [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ]);

        return Http::withHeaders(['Content-Type' => 'application/json;charset=utf-8'])
            ->post('https://'.config('services.docusign.base_url').'/oauth/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ])
            ->json();
    }

    /**
     * Build a DocuSign Signer with a SignHere tab anchored to the given anchor string.
     *
     * The tab is positioned via setAnchorString() — DocuSign scans the document for
     * the exact text and places the tab there. Offsets (in inches) fine-tune placement:
     * X: 0.2" right of anchor start, Y: -0.3" above anchor baseline.
     *
     * clientUserId is set to recipientId so that embedded signing URLs can be generated.
     *
     * @param  string  $email  Signer's email.
     * @param  string  $name  Signer's display name.
     * @param  string  $anchorString  Text in the document that locates this tab (e.g. "-FIRMA1").
     * @param  string  $recipientId  Numeric string ("1", "2", ...).
     */
    private function buildSigner(
        string $email,
        string $name,
        string $anchorString,
        string $recipientId,
    ): Signer {
        $signHere = new SignHere;
        $signHere->setDocumentId('1');
        $signHere->setRecipientId($recipientId);
        $signHere->setTabLabel($anchorString);
        $signHere->setAnchorString($anchorString);
        $signHere->setAnchorUnits('inches');
        $signHere->setAnchorXOffset('0.2');
        $signHere->setAnchorYOffset('-0.3');

        $tabs = new Tabs;
        $tabs->setSignHereTabs([$signHere]);

        $signer = new Signer;
        $signer->setEmail($email);
        $signer->setName($name);
        $signer->setRecipientId($recipientId);
        $signer->setClientUserId($recipientId);
        $signer->setTabs($tabs);

        return $signer;
    }
}
