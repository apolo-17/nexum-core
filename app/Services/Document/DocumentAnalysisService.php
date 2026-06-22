<?php

namespace App\Services\Document;

use App\Enums\DocumentTypeEnum;
use App\Models\Document;
use App\Models\DocumentAnalysis;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Extracts structured identity data from KYC documents using Claude vision API.
 *
 * Sends the document image (or PDF) to Anthropic's Claude claude-opus-4-8 model and parses
 * the structured JSON response into a DocumentAnalysis record. Supports three
 * document categories with dedicated extraction prompts:
 *
 *   - Passport / national identity → document_number, gender, nationality, birthdate, birthplace, expiry_date
 *   - Proof of address             → address, country_of_residence
 *   - Marriage certificate         → matrimonial_regime (sociedad_conyugal or separacion_de_bienes)
 *
 * Documents of other types are skipped silently (no analysis record created).
 */
class DocumentAnalysisService
{
    /**
     * Anthropic API endpoint for the Messages API.
     */
    private const ANTHROPIC_API_URL = 'https://api.anthropic.com/v1/messages';

    /**
     * API version header required by Anthropic.
     */
    private const ANTHROPIC_VERSION = '2023-06-01';

    /**
     * Claude model to use for document analysis.
     * Opus 4.8 is the most capable vision model, chosen for the highest accuracy
     * extracting identity fields from KYC documents.
     */
    private const CLAUDE_MODEL = 'claude-opus-4-8';

    /**
     * Analyse a document and persist the extracted fields as a DocumentAnalysis record.
     *
     * Downloads the file from storage, builds the appropriate extraction prompt
     * based on the document type, calls the Claude API, and upserts a
     * DocumentAnalysis record with the extracted data.
     *
     * Returns null for document types that are not analysable (no-op).
     *
     * @param  Document  $document  The approved document to analyse.
     * @return DocumentAnalysis|null The persisted analysis record, or null if skipped.
     *
     * @throws \RuntimeException When the API call fails or the file cannot be downloaded.
     */
    public function analyse(Document $document): ?DocumentAnalysis
    {
        if (! $this->isAnalysable($document)) {
            return null;
        }

        $fileContents = $this->downloadFile($document);
        $mediaType = $this->resolveMediaType($document);
        $prompt = $this->buildPrompt($document->type);
        $base64Content = base64_encode($fileContents);

        $extracted = $this->callClaudeApi($base64Content, $mediaType, $prompt);

        return $this->persistAnalysis($document, $extracted);
    }

    // -------------------------------------------------------------------------
    // Private — extraction pipeline
    // -------------------------------------------------------------------------

    /**
     * Return true if this document type should be sent to Claude for analysis.
     *
     * Only documents that carry extractable identity or address data are analysed.
     * Other types (acta constitutiva renders, DocuSign envelopes, etc.) are skipped.
     *
     * @param  Document  $document  Document to check.
     */
    private function isAnalysable(Document $document): bool
    {
        return in_array($document->type, [
            DocumentTypeEnum::PASSPORT,           // Shareholder's own passport (naturalPassport{N})
            DocumentTypeEnum::KYC_TAX_CERTIFICATE, // Chinese national ID / tax certificate (naturalTaxCertificate{N})
            DocumentTypeEnum::KYC_PROOF_OF_ADDRESS,
            DocumentTypeEnum::KYC_MARRIAGE_CERTIFICATE,
            DocumentTypeEnum::KYC_SPOUSE_PASSPORT,
        ], strict: true);
    }

    /**
     * Download the raw file contents from the configured storage disk.
     *
     * @param  Document  $document  Document whose storage_path will be read.
     * @return string Raw binary file contents.
     *
     * @throws \RuntimeException When the file does not exist in storage.
     */
    private function downloadFile(Document $document): string
    {
        $contents = Storage::get($document->storage_path);

        if ($contents === null) {
            throw new \RuntimeException(
                "DocumentAnalysisService: file not found in storage for document {$document->id} at path {$document->storage_path}."
            );
        }

        return $contents;
    }

    /**
     * Resolve the MIME type to pass in the Anthropic API request.
     *
     * Claude accepts image/jpeg, image/png, image/gif, image/webp, and application/pdf.
     *
     * @param  Document  $document  Document whose extension is checked.
     * @return string MIME type string.
     */
    private function resolveMediaType(Document $document): string
    {
        $ext = strtolower(pathinfo($document->storage_path, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    /**
     * Build the extraction prompt for the given document type.
     *
     * Each prompt instructs Claude to return ONLY a JSON object with specific
     * fields, using null for fields that cannot be found. No extra text should
     * be returned so the response can be parsed directly.
     *
     * @param  DocumentTypeEnum  $type  The document type to build a prompt for.
     * @return string The instruction prompt to send alongside the document image.
     */
    private function buildPrompt(DocumentTypeEnum $type): string
    {
        return match ($type) {
            DocumentTypeEnum::PASSPORT,
            DocumentTypeEnum::KYC_SPOUSE_PASSPORT,
            DocumentTypeEnum::KYC_TAX_CERTIFICATE => <<<'PROMPT'
                You are a document data extraction assistant. Extract the following fields from this identity document (passport or national ID) and return ONLY a valid JSON object with no additional text or explanation.

                Required JSON structure:
                {
                  "document_number": "string or null",
                  "gender": "M or F or null",
                  "nationality": "string (country name in English) or null",
                  "birthdate": "YYYY-MM-DD or null",
                  "birthplace": "city and country as printed or null",
                  "expiry_date": "YYYY-MM-DD or null"
                }

                Rules:
                - gender must be exactly "M" or "F", never full words
                - dates must be in ISO 8601 format (YYYY-MM-DD)
                - nationality should be the country name in English (e.g. "China", "Mexico")
                - if a field is not visible or not present, use null
                - return ONLY the JSON object, nothing else
                PROMPT,

            DocumentTypeEnum::KYC_PROOF_OF_ADDRESS => <<<'PROMPT'
                You are a document data extraction assistant. Extract the residential address from this proof-of-address document and return ONLY a valid JSON object with no additional text or explanation.

                Required JSON structure:
                {
                  "address": "full address as printed, including street, number, city, state, postal code or null",
                  "country_of_residence": "country name in English or null"
                }

                Rules:
                - address should be the complete address as it appears in the document
                - country_of_residence should be the country name in English (e.g. "China", "Mexico")
                - if a field is not visible or not present, use null
                - return ONLY the JSON object, nothing else
                PROMPT,

            DocumentTypeEnum::KYC_MARRIAGE_CERTIFICATE => <<<'PROMPT'
                You are a document data extraction assistant. Analyse this marriage certificate and determine the matrimonial property regime. Return ONLY a valid JSON object with no additional text or explanation.

                Required JSON structure:
                {
                  "matrimonial_regime": "sociedad_conyugal or separacion_de_bienes or null"
                }

                Rules:
                - "sociedad_conyugal" means community property / joint property regime (assets are shared)
                - "separacion_de_bienes" means separate property regime (each spouse keeps their own assets)
                - if the document is a standard Chinese marriage certificate with no explicit regime clause, use "sociedad_conyugal" as the default (most common)
                - if the regime is explicitly stated as separate property, use "separacion_de_bienes"
                - if the document does not contain enough information to determine the regime, use null
                - return ONLY the JSON object, nothing else
                PROMPT,

            default => '{"error": "unsupported document type"}',
        };
    }

    /**
     * Send the document to the Claude vision API and return the parsed JSON response.
     *
     * Uses Laravel's HTTP client. The document is sent as a base64-encoded inline
     * source so no public URL is required. Claude claude-opus-4-8 supports both image
     * formats and PDF documents natively.
     *
     * @param  string  $base64Content  Base64-encoded file contents.
     * @param  string  $mediaType  MIME type of the document.
     * @param  string  $prompt  Extraction instruction prompt.
     * @return array<string, mixed> Parsed JSON extracted from Claude's response.
     *
     * @throws \RuntimeException When the API returns a non-200 response.
     */
    private function callClaudeApi(string $base64Content, string $mediaType, string $prompt): array
    {
        $apiKey = config('services.anthropic.api_key');

        // Determine content block type: Claude uses 'document' for PDFs and 'image' for images.
        $contentBlockType = $mediaType === 'application/pdf' ? 'document' : 'image';

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
            'content-type' => 'application/json',
        ])->post(self::ANTHROPIC_API_URL, [
            'model' => self::CLAUDE_MODEL,
            'max_tokens' => 512,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => $contentBlockType,
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $mediaType,
                                'data' => $base64Content,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                    ],
                ],
            ],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Claude API error {$response->status()}: {$response->body()}"
            );
        }

        $responseBody = $response->json();
        $rawText = $responseBody['content'][0]['text'] ?? '';

        // Strip markdown code fences if Claude wrapped the JSON in ```json ... ```.
        $jsonText = preg_replace('/^```(?:json)?\s*/m', '', $rawText);
        $jsonText = preg_replace('/\s*```$/m', '', $jsonText ?? $rawText);

        $parsed = json_decode(trim($jsonText ?? $rawText), associative: true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('DocumentAnalysisService: Claude returned non-JSON response.', [
                'raw_text' => $rawText,
            ]);

            return ['_raw' => $rawText];
        }

        return $parsed;
    }

    /**
     * Persist or update the DocumentAnalysis record from the extracted data.
     *
     * Uses updateOrCreate keyed by document_id so re-running the job on the
     * same document refreshes the extracted data rather than creating a duplicate.
     *
     * @param  Document  $document  The source document.
     * @param  array<string, mixed>  $extracted  Parsed fields from Claude's response.
     * @return DocumentAnalysis The upserted analysis record.
     */
    private function persistAnalysis(Document $document, array $extracted): DocumentAnalysis
    {
        $hasError = isset($extracted['error']) || isset($extracted['_raw']);

        $data = [
            'analyzed' => ! $hasError,
            'raw_response' => $extracted,
            'error_message' => $hasError ? ($extracted['error'] ?? $extracted['_raw'] ?? 'Unexpected response format') : null,
        ];

        // Map extracted fields to their respective columns.
        if (! $hasError) {
            $data['document_number'] = $extracted['document_number'] ?? null;
            $data['gender'] = $extracted['gender'] ?? null;
            $data['nationality'] = $extracted['nationality'] ?? null;
            $data['birthdate'] = $extracted['birthdate'] ?? null;
            $data['birthplace'] = $extracted['birthplace'] ?? null;
            $data['expiry_date'] = $extracted['expiry_date'] ?? null;
            $data['address'] = $extracted['address'] ?? null;
            $data['country_of_residence'] = $extracted['country_of_residence'] ?? null;
            $data['matrimonial_regime'] = $extracted['matrimonial_regime'] ?? null;
        }

        return DocumentAnalysis::updateOrCreate(
            ['document_id' => $document->id],
            $data,
        );
    }
}
