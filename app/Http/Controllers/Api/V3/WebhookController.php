<?php

namespace App\Http\Controllers\Api\V3;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDocuSignWebhookJob;
use App\Jobs\ProcessSingapurWebhook;
use App\Models\WebhookEvent;
use App\Services\DocuSign\DocuSignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives and queues incoming webhook events from external relay systems.
 *
 * The endpoint stores the raw payload immediately and dispatches a background job
 * for processing. This keeps response time minimal and guarantees idempotency
 * via the event_id unique constraint on the webhook_events table.
 */
class WebhookController extends Controller
{
    /**
     * Receive a webhook event from the Singapur relay.
     *
     * Validates the shared-secret header, checks idempotency, persists the event,
     * and dispatches the processing job asynchronously.
     *
     * @param  Request  $request  Incoming HTTP request with event payload.
     * @return JsonResponse 202 Accepted on success, 409 if already received, 401 if unauthorized.
     */
    public function singapur(Request $request): JsonResponse
    {
        // Validate the shared secret sent by the relay in the X-Nexum-Secret header.
        // Use hash_equals for a timing-safe comparison and reject when no secret is configured.
        $secret = config('services.singapur.webhook_secret');
        $provided = (string) $request->header('X-Nexum-Secret');

        if (! is_string($secret) || $secret === '' || ! hash_equals($secret, $provided)) {
            return response()->json(
                ['error' => 'Unauthorized'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        // China sends the submission UUID in the `id` field (see submission.json).
        $eventId = $request->input('id');

        if (blank($eventId)) {
            return response()->json(
                ['error' => 'Missing id'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Idempotency check — if the event already exists, skip silently.
        if (WebhookEvent::where('event_id', $eventId)->exists()) {
            return response()->json(
                ['message' => 'Event already received'],
                Response::HTTP_CONFLICT,
            );
        }

        // Persist the raw event before dispatching to avoid losing data on job failure.
        $webhookEvent = WebhookEvent::create([
            'event_id' => $eventId,
            'source' => 'singapur_relay',
            'payload' => $request->all(),
            'status' => 'pending',
        ]);

        ProcessSingapurWebhook::dispatch($webhookEvent);

        return response()->json(
            ['message' => 'Event accepted'],
            Response::HTTP_ACCEPTED,
        );
    }

    /**
     * Receive DocuSign Connect webhook notifications.
     *
     * DocuSign sends a POST with an XML body (or JSON, depending on the Connect config)
     * to this endpoint whenever an envelope status changes. We validate the HMAC
     * signature from the X-DocuSign-Signature-1 header before processing.
     *
     * The raw payload is queued via ProcessDocuSignWebhookJob so the response
     * is returned to DocuSign within the 5-second timeout requirement.
     *
     * @param  Request  $request  Incoming webhook from DocuSign Connect.
     * @return JsonResponse 202 on success, 401 if HMAC is invalid.
     */
    public function docuSign(Request $request): JsonResponse
    {
        $providedHmac = (string) $request->header('X-DocuSign-Signature-1', '');

        if ($providedHmac === '') {
            return response()->json(
                ['error' => 'Missing HMAC signature'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        /** @var DocuSignService $docuSignService */
        $docuSignService = resolve(DocuSignService::class);

        if (! $docuSignService->verifyHmac($request->getContent(), $providedHmac)) {
            return response()->json(
                ['error' => 'Unauthorized — invalid HMAC'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        ProcessDocuSignWebhookJob::dispatch(
            payload: $request->all(),
            rawBody: $request->getContent(),
        );

        return response()->json(
            ['message' => 'Event accepted'],
            Response::HTTP_ACCEPTED,
        );
    }
}
