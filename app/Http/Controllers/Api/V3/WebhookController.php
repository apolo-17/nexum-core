<?php

namespace App\Http\Controllers\Api\V3;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSingapurWebhook;
use App\Models\WebhookEvent;
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
     * @return JsonResponse       202 Accepted on success, 409 if already received, 401 if unauthorized.
     */
    public function singapur(Request $request): JsonResponse
    {
        // Validate the shared secret sent by the relay in the X-Nexum-Secret header.
        $secret = config('services.singapur.webhook_secret');

        if ($request->header('X-Nexum-Secret') !== $secret) {
            return response()->json(
                ['error' => 'Unauthorized'],
                Response::HTTP_UNAUTHORIZED,
            );
        }

        $eventId = $request->input('event_id');

        if (blank($eventId)) {
            return response()->json(
                ['error' => 'Missing event_id'],
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
            'source'   => 'singapur_relay',
            'payload'  => $request->all(),
            'status'   => 'pending',
        ]);

        ProcessSingapurWebhook::dispatch($webhookEvent);

        return response()->json(
            ['message' => 'Event accepted'],
            Response::HTTP_ACCEPTED,
        );
    }
}
