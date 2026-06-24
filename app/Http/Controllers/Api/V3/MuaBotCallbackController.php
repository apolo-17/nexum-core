<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V3;

use App\Enums\LegalNameStatusEnum;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\LegalName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives callbacks from the MUA bot when the Secretaría de Economía resolves
 * a denomination (APPROVED or REJECTED).
 *
 * Security: requests are authenticated via HMAC-SHA256 signature in the
 * X-Signature header. The bot and this endpoint share a secret defined in
 * config('services.mua_bot.secret_key').
 *
 * When approved, the bot sends the "constancia de autorización de denominación
 * social" (PDF) as a base64 string. This controller saves it to S3, creates the
 * Document record, and marks the LegalName as APPROVED.
 */
class MuaBotCallbackController extends Controller
{
    /**
     * Maximum allowed age of a request timestamp in seconds (5 minutes).
     * Prevents replay attacks.
     *
     * @var int
     */
    private const MAX_TIMESTAMP_DIFF_SECONDS = 300;

    /**
     * Receive and process an APPROVED denomination callback from the MUA bot.
     *
     * Expected payload:
     *   - legal_name_id          string   ULID of the LegalName record.
     *   - status                 string   "approved" or "rejected".
     *   - clave_unica            string   SE authorization key (required when approved).
     *   - authorization_at       string   ISO-8601 datetime of SE authorization (required when approved).
     *   - constancia_pdf_base64  string   Base64-encoded constancia PDF (required when approved).
     *   - rejection_reason       string   SE rejection category (required when rejected).
     *   - timestamp              int      Unix timestamp of the request (for replay protection).
     *
     * @param  Request  $request
     *
     * @return JsonResponse
     */
    public function handle(Request $request): JsonResponse
    {
        // --- HMAC authentication ---
        $signature = $request->header('X-Signature');

        if (! $signature || ! is_string($signature)) {
            return response()->json(['error' => 'Missing signature'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = [
            'legal_name_id' => (string) $request->input('legal_name_id'),
            'status'        => (string) $request->input('status'),
            'timestamp'     => (int)    $request->input('timestamp'),
        ];

        if (! $this->isValidSignature($payload, $signature)) {
            Log::warning('MUA bot callback: invalid HMAC signature.', ['ip' => $request->ip()]);

            return response()->json(['error' => 'Invalid signature'], Response::HTTP_UNAUTHORIZED);
        }

        if (abs(time() - $payload['timestamp']) > self::MAX_TIMESTAMP_DIFF_SECONDS) {
            return response()->json(['error' => 'Request expired'], Response::HTTP_UNAUTHORIZED);
        }

        // --- Locate the denomination ---
        $legalName = LegalName::with('registration')->find($payload['legal_name_id']);

        if (! $legalName) {
            return response()->json(['error' => 'Legal name not found'], Response::HTTP_NOT_FOUND);
        }

        $status = LegalNameStatusEnum::tryFrom($payload['status']);

        if (! in_array($status, [LegalNameStatusEnum::APPROVED, LegalNameStatusEnum::REJECTED], true)) {
            return response()->json(['error' => 'Invalid status value'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // --- Process result ---
        try {
            if ($status === LegalNameStatusEnum::APPROVED) {
                $this->processApproval($request, $legalName);
            } else {
                $this->processRejection($request, $legalName);
            }
        } catch (\Throwable $th) {
            Log::error('MUA bot callback: failed to process denomination result.', [
                'legal_name_id' => $legalName->id,
                'status'        => $status->value,
                'exception'     => $th->getMessage(),
            ]);

            return response()->json(['error' => 'Processing failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json(['message' => 'Denomination result recorded.'], Response::HTTP_OK);
    }

    /**
     * Handle an approved denomination: save the constancia PDF to S3,
     * create the authorization Document record, and mark the LegalName APPROVED.
     *
     * @param  Request    $request    Validated request with PDF data.
     * @param  LegalName  $legalName  The denomination being resolved.
     *
     * @return void
     *
     * @throws \RuntimeException When the constancia PDF cannot be decoded or stored.
     */
    private function processApproval(Request $request, LegalName $legalName): void
    {
        $claveUnica      = (string) $request->input('clave_unica');
        $authorizationAt = (string) $request->input('authorization_at');
        $pdfBase64       = (string) $request->input('constancia_pdf_base64');
        $portalStatus    = (string) $request->input('portal_status', 'Autorizada');

        if (! $claveUnica || ! $authorizationAt || ! $pdfBase64) {
            throw new \RuntimeException('Missing required fields for approval: clave_unica, authorization_at, constancia_pdf_base64.');
        }

        $pdfContent = base64_decode($pdfBase64, strict: true);

        if ($pdfContent === false) {
            throw new \RuntimeException('Invalid base64 constancia PDF.');
        }

        $registration = $legalName->registration;
        $s3Path       = "registrations/{$registration->id}/constancia_denominacion_{$legalName->id}.pdf";

        DB::transaction(function () use ($legalName, $registration, $claveUnica, $authorizationAt, $portalStatus, $pdfContent, $s3Path): void {
            // Persist constancia PDF to S3.
            Storage::disk('s3')->put($s3Path, $pdfContent);

            // Create the authorization Document record.
            Document::updateOrCreate(
                [
                    'registration_id' => $registration->id,
                    'document_type'   => 'legal_name_authorization',
                ],
                [
                    'file_path'  => $s3Path,
                    'is_approved' => true,
                ]
            );

            // Reject all other denominations for this registration.
            LegalName::where('registration_id', $registration->id)
                ->where('id', '!=', $legalName->id)
                ->update([
                    'status'           => LegalNameStatusEnum::REJECTED->value,
                    'rejection_reason' => 'Rechazada automáticamente al aprobarse otra denominación.',
                ]);

            // Approve this denomination.
            $legalName->update([
                'status'                   => LegalNameStatusEnum::APPROVED->value,
                'clave_unica_denominacion' => $claveUnica,
                'authorization_timestamp'  => $authorizationAt,
                'portal_status'            => $portalStatus,
            ]);

            // Decrement the soldado's active submission counter.
            if ($legalName->muaAccount) {
                $legalName->muaAccount->decrement('active_submissions');
            }
        });

        Log::info('MUA bot callback: denomination APPROVED and constancia saved.', [
            'legal_name_id' => $legalName->id,
            'name'          => $legalName->name,
            'clave_unica'   => $claveUnica,
            's3_path'       => $s3Path,
        ]);
    }

    /**
     * Handle a rejected denomination: mark it REJECTED and record the SE's reason.
     *
     * @param  Request    $request    Request containing the rejection reason.
     * @param  LegalName  $legalName  The denomination being resolved.
     *
     * @return void
     */
    private function processRejection(Request $request, LegalName $legalName): void
    {
        $reason       = (string) $request->input('rejection_reason', 'Rechazada por la Secretaría de Economía.');
        $portalStatus = (string) $request->input('portal_status', 'Rechazada por dictamen');

        $legalName->update([
            'status'           => LegalNameStatusEnum::REJECTED->value,
            'rejection_reason' => $reason,
            'portal_status'    => $portalStatus,
        ]);

        if ($legalName->muaAccount) {
            $legalName->muaAccount->decrement('active_submissions');
        }

        Log::info('MUA bot callback: denomination REJECTED.', [
            'legal_name_id' => $legalName->id,
            'name'          => $legalName->name,
            'reason'        => $reason,
        ]);
    }

    /**
     * Verify the HMAC-SHA256 signature of the request.
     *
     * Keys are sorted alphabetically before encoding to ensure a canonical payload —
     * both the bot and this controller must apply the same sorting.
     *
     * @param  array<string, mixed>  $payload    Extracted fields to sign.
     * @param  string                $signature  HMAC hex digest from the X-Signature header.
     *
     * @return bool
     */
    private function isValidSignature(array $payload, string $signature): bool
    {
        ksort($payload);
        $canonical        = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $expectedSignature = hash_hmac('sha256', $canonical, config('services.mua_bot.secret_key'));

        return hash_equals($expectedSignature, $signature);
    }
}
