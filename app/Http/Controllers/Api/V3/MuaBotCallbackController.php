<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V3;

use App\Enums\LegalNameEventTypeEnum;
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
            'status' => (string) $request->input('status'),
            'timestamp' => (int) $request->input('timestamp'),
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

        $callbackStatus = $payload['status'];

        // The bot's callback vocabulary, decoupled from our internal status enum:
        //   submitted → the SE confirmed registration (advances SUBMITTING → PENDING)
        //   failed    → a bot-side failure (submit or check) — never stays silent
        //   process   → still in dictamen
        //   approved / rejected → terminal resolution
        $allowed = ['submitted', 'failed', 'process', 'approved', 'rejected'];

        if (! in_array($callbackStatus, $allowed, true)) {
            return response()->json(['error' => 'Invalid status value'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // --- Process result ---
        try {
            switch ($callbackStatus) {
                case 'approved':
                    $this->processApproval($request, $legalName);

                    $legalName->refresh();
                    $legalName->recordEvent(
                        LegalNameEventTypeEnum::APPROVED,
                        'La SE autorizó la denominación.',
                        [
                            'clave_unica' => $legalName->clave_unica_denominacion,
                            'portal_status' => $legalName->portal_status,
                        ],
                        actorType: 'bot',
                    );
                    $legalName->recordEvent(
                        LegalNameEventTypeEnum::CONSTANCIA_RECEIVED,
                        'Constancia de autorización recibida.',
                        actorType: 'bot',
                    );
                    $this->clearPendingCheck($legalName);
                    break;

                case 'rejected':
                    $this->processRejection($request, $legalName);

                    $legalName->refresh();
                    $legalName->recordEvent(
                        LegalNameEventTypeEnum::REJECTED,
                        'La SE rechazó la denominación.',
                        [
                            'reason' => $legalName->rejection_reason,
                            'portal_status' => $legalName->portal_status,
                        ],
                        actorType: 'bot',
                    );
                    $this->clearPendingCheck($legalName);
                    break;

                case 'submitted':
                    $this->processSubmissionConfirmed($request, $legalName);
                    break;

                case 'process':
                    $this->processStillInReview($request, $legalName);
                    break;

                case 'failed':
                    $this->processFailed($request, $legalName);
                    break;
            }
        } catch (\Throwable $th) {
            Log::error('MUA bot callback: failed to process denomination result.', [
                'legal_name_id' => $legalName->id,
                'status' => $callbackStatus,
                'exception' => $th->getMessage(),
            ]);

            return response()->json(['error' => 'Processing failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json(['message' => 'Denomination result recorded.'], Response::HTTP_OK);
    }

    /**
     * Handle an approved denomination: save the constancia PDF to S3,
     * create the authorization Document record, and mark the LegalName APPROVED.
     *
     * @param  Request  $request  Validated request with PDF data.
     * @param  LegalName  $legalName  The denomination being resolved.
     *
     * @throws \RuntimeException When the constancia PDF cannot be decoded or stored.
     */
    private function processApproval(Request $request, LegalName $legalName): void
    {
        $claveUnica = (string) $request->input('clave_unica');
        $authorizationAt = (string) $request->input('authorization_at');
        $pdfBase64 = (string) $request->input('constancia_pdf_base64');
        $portalStatus = (string) $request->input('portal_status', 'Autorizada');

        if (! $claveUnica || ! $authorizationAt || ! $pdfBase64) {
            throw new \RuntimeException('Missing required fields for approval: clave_unica, authorization_at, constancia_pdf_base64.');
        }

        $pdfContent = base64_decode($pdfBase64, strict: true);

        if ($pdfContent === false) {
            throw new \RuntimeException('Invalid base64 constancia PDF.');
        }

        $registration = $legalName->registration;

        // Pool denominations have no expedient: approve standalone (no Document, no
        // "reject the others") so the name becomes available for China to claim.
        if ($registration === null) {
            $this->approvePoolDenomination($legalName, $claveUnica, $authorizationAt, $portalStatus, $pdfContent);

            return;
        }

        $s3Path = "registrations/{$registration->id}/constancia_denominacion_{$legalName->id}.pdf";

        DB::transaction(function () use ($legalName, $registration, $claveUnica, $authorizationAt, $portalStatus, $pdfContent, $s3Path): void {
            // Persist constancia PDF to S3.
            Storage::disk('s3')->put($s3Path, $pdfContent);

            // Create the authorization Document record.
            Document::updateOrCreate(
                [
                    'registration_id' => $registration->id,
                    'document_type' => 'legal_name_authorization',
                ],
                [
                    'file_path' => $s3Path,
                    'is_approved' => true,
                ]
            );

            // Reject all other denominations for this registration.
            LegalName::where('registration_id', $registration->id)
                ->where('id', '!=', $legalName->id)
                ->update([
                    'status' => LegalNameStatusEnum::REJECTED->value,
                    'rejection_reason' => 'Rechazada automáticamente al aprobarse otra denominación.',
                ]);

            // Approve this denomination.
            $legalName->update([
                'status' => LegalNameStatusEnum::APPROVED->value,
                'clave_unica_denominacion' => $claveUnica,
                'authorization_timestamp' => $authorizationAt,
                'portal_status' => $portalStatus,
            ]);

            // Decrement the soldado's active submission counter.
            if ($legalName->soldado) {
                $legalName->soldado->decrement('active_submissions');
            }
        });

        Log::info('MUA bot callback: denomination APPROVED and constancia saved.', [
            'legal_name_id' => $legalName->id,
            'name' => $legalName->name,
            'clave_unica' => $claveUnica,
            's3_path' => $s3Path,
        ]);
    }

    /**
     * Approve a standalone pool denomination (one with no registration).
     *
     * Stores the constancia under a pool path and marks the LegalName APPROVED so
     * it shows up in the available-pool API for the China front to claim. No
     * Document record is created (it requires a registration) and no sibling
     * denominations are rejected (there is no expedient).
     *
     * @param  LegalName  $legalName  The approved pool denomination.
     * @param  string  $claveUnica  SE authorization key.
     * @param  string  $authorizationAt  ISO-8601 SE authorization datetime.
     * @param  string  $portalStatus  Raw SE portal status label.
     * @param  string  $pdfContent  Decoded constancia PDF bytes.
     */
    private function approvePoolDenomination(
        LegalName $legalName,
        string $claveUnica,
        string $authorizationAt,
        string $portalStatus,
        string $pdfContent,
    ): void {
        $s3Path = "denominations/pool/constancia_denominacion_{$legalName->id}.pdf";

        DB::transaction(function () use ($legalName, $claveUnica, $authorizationAt, $portalStatus, $pdfContent, $s3Path): void {
            Storage::disk('s3')->put($s3Path, $pdfContent);

            $legalName->update([
                'status' => LegalNameStatusEnum::APPROVED->value,
                'clave_unica_denominacion' => $claveUnica,
                'authorization_timestamp' => $authorizationAt,
                'portal_status' => $portalStatus,
            ]);

            if ($legalName->soldado) {
                $legalName->soldado->decrement('active_submissions');
            }
        });

        Log::info('MUA bot callback: POOL denomination APPROVED and constancia saved.', [
            'legal_name_id' => $legalName->id,
            'name' => $legalName->name,
            'clave_unica' => $claveUnica,
            's3_path' => $s3Path,
        ]);
    }

    /**
     * Handle a rejected denomination: mark it REJECTED and record the SE's reason.
     *
     * @param  Request  $request  Request containing the rejection reason.
     * @param  LegalName  $legalName  The denomination being resolved.
     */
    private function processRejection(Request $request, LegalName $legalName): void
    {
        $reason = (string) $request->input('rejection_reason', 'Rechazada por la Secretaría de Economía.');
        $portalStatus = (string) $request->input('portal_status', 'Rechazada por dictamen');

        $legalName->update([
            'status' => LegalNameStatusEnum::REJECTED->value,
            'rejection_reason' => $reason,
            'portal_status' => $portalStatus,
        ]);

        if ($legalName->soldado) {
            $legalName->soldado->decrement('active_submissions');
        }

        Log::info('MUA bot callback: denomination REJECTED.', [
            'legal_name_id' => $legalName->id,
            'name' => $legalName->name,
            'reason' => $reason,
        ]);
    }

    /**
     * Handle a non-terminal PROCESS callback: the SE still has the denomination
     * in review. Delivered by on-demand status checks so the dashboard can show
     * "sigue en dictamen" rather than waiting in silence.
     *
     * Advances PENDING → PROCESS, refreshes the raw SE portal label and clears the
     * pending-check flag so the "Consultando…" indicator stops. Stale callbacks for
     * an already-resolved denomination only clear the flag — a terminal status is
     * never downgraded.
     *
     * @param  Request  $request  Request carrying the optional portal_status label.
     * @param  LegalName  $legalName  The denomination being polled.
     */
    private function processStillInReview(Request $request, LegalName $legalName): void
    {
        if (! $legalName->isInProcess()) {
            $this->clearPendingCheck($legalName);

            return;
        }

        $portalStatus = (string) $request->input('portal_status', '');

        // Detect a meaningful change BEFORE mutating: first entry into dictamen
        // (PENDING → PROCESS) or a new SE portal label. The scheduled /poll touches
        // a denomination every cycle; logging each one would flood the timeline, so
        // we only record an event when something actually changed.
        $enteredReview = $legalName->status === LegalNameStatusEnum::PENDING;
        $portalChanged = $portalStatus !== '' && $portalStatus !== $legalName->portal_status;

        $legalName->update([
            'status' => LegalNameStatusEnum::PROCESS->value,
            'portal_status' => $portalStatus !== '' ? $portalStatus : $legalName->portal_status,
            'last_status_check_at' => null,
        ]);

        if ($enteredReview || $portalChanged) {
            $legalName->recordEvent(
                LegalNameEventTypeEnum::IN_PROCESS,
                $enteredReview
                    ? 'La SE tomó la denominación a dictamen.'
                    : 'La SE actualizó el estatus en dictamen.',
                ['portal_status' => $portalStatus ?: null],
                actorType: 'bot',
            );
        }

        Log::info('MUA bot callback: denomination still in review.', [
            'legal_name_id' => $legalName->id,
            'name' => $legalName->name,
            'portal_status' => $portalStatus,
            'event_logged' => $enteredReview || $portalChanged,
        ]);
    }

    /**
     * Handle a `submitted` callback: the bot confirmed the SE actually registered
     * the denomination. This advances the honest in-flight SUBMITTING state to
     * PENDING ("Enviada a la SE"), so that label always reflects a confirmed fact.
     *
     * Idempotent and non-regressive: only advances from SUBMITTING (or re-affirms an
     * existing PENDING). A stale confirmation for a denomination already in dictamen
     * or resolved is ignored — a later state is never downgraded.
     *
     * @param  Request  $request  Request carrying the optional portal_status label.
     * @param  LegalName  $legalName  The denomination whose registration is confirmed.
     */
    private function processSubmissionConfirmed(Request $request, LegalName $legalName): void
    {
        if (! in_array($legalName->status, [
            LegalNameStatusEnum::SUBMITTING,
            LegalNameStatusEnum::PENDING,
        ], true)) {
            return;
        }

        $portalStatus = (string) $request->input('portal_status', '');
        $wasSubmitting = $legalName->status === LegalNameStatusEnum::SUBMITTING;

        $legalName->update([
            'status' => LegalNameStatusEnum::PENDING->value,
            'portal_status' => $portalStatus !== '' ? $portalStatus : $legalName->portal_status,
            'last_status_check_at' => null,
        ]);

        if ($wasSubmitting) {
            $legalName->recordEvent(
                LegalNameEventTypeEnum::SUBMITTED,
                'El bot confirmó que la SE registró la denominación.',
                ['portal_status' => $portalStatus ?: null],
                actorType: 'bot',
            );
        }

        Log::info('MUA bot callback: submission confirmed by SE.', [
            'legal_name_id' => $legalName->id,
            'name' => $legalName->name,
        ]);
    }

    /**
     * Handle a `failed` callback: the bot's background task could not complete.
     *
     * Branches on the current state so the failure is honest:
     *   - SUBMITTING → registration failed; return the name to the queue (WAIT),
     *     release its FIEL so it can be reassigned, and record the reason.
     *   - PENDING / PROCESS → a status check or poll failed; keep the (already real)
     *     status, surface the failure and clear the loading flag.
     *
     * @param  Request  $request  Request carrying the optional failure reason.
     * @param  LegalName  $legalName  The denomination whose operation failed.
     */
    private function processFailed(Request $request, LegalName $legalName): void
    {
        $reason = (string) $request->input('reason', 'El bot no pudo completar la operación.');

        if ($legalName->status === LegalNameStatusEnum::SUBMITTING) {
            $legalName->update([
                'status' => LegalNameStatusEnum::WAIT->value,
                'soldado_id' => null,
                'submitted_at' => null,
                'last_status_check_at' => null,
            ]);

            $legalName->recordEvent(
                LegalNameEventTypeEnum::SUBMISSION_FAILED,
                'El bot no pudo registrar la denominación en la SE. Regresó a la cola.',
                ['reason' => $reason],
                actorType: 'bot',
            );

            Log::warning('MUA bot callback: submission failed, returned to queue.', [
                'legal_name_id' => $legalName->id,
                'name' => $legalName->name,
                'reason' => $reason,
            ]);

            return;
        }

        // A check / poll failed for an already-submitted denomination: keep status.
        $legalName->recordEvent(
            LegalNameEventTypeEnum::CHECK_FAILED,
            'El bot no pudo consultar el estado en la SE.',
            ['reason' => $reason],
            actorType: 'bot',
        );
        $this->clearPendingCheck($legalName);

        Log::warning('MUA bot callback: status check failed.', [
            'legal_name_id' => $legalName->id,
            'name' => $legalName->name,
            'reason' => $reason,
        ]);
    }

    /**
     * Clear the pending manual-check flag so the "Consultando…" indicator stops.
     *
     * Called once a result arrives (approved, rejected or still in review) so the
     * loading badge clears immediately instead of waiting out the grace window.
     *
     * @param  LegalName  $legalName  The denomination whose check has resolved.
     */
    private function clearPendingCheck(LegalName $legalName): void
    {
        if ($legalName->last_status_check_at !== null) {
            $legalName->update(['last_status_check_at' => null]);
        }
    }

    /**
     * Verify the HMAC-SHA256 signature of the request.
     *
     * Keys are sorted alphabetically before encoding to ensure a canonical payload —
     * both the bot and this controller must apply the same sorting.
     *
     * @param  array<string, mixed>  $payload  Extracted fields to sign.
     * @param  string  $signature  HMAC hex digest from the X-Signature header.
     */
    private function isValidSignature(array $payload, string $signature): bool
    {
        ksort($payload);
        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $expectedSignature = hash_hmac('sha256', $canonical, config('services.mua_bot.secret_key'));

        return hash_equals($expectedSignature, $signature);
    }
}
