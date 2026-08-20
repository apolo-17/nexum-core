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

        // Validate the submission envelope AND the business fields the acta render
        // strictly needs. Declaring the rules here also feeds Scramble's auto-generated
        // API docs. We now enforce the render-critical `fields.*` so the relay is forced
        // to send a complete payload — an incomplete submission fails fast with 422
        // instead of being accepted (202) and then producing a blank/defaulted acta.
        $validated = $request->validate([
            // Submission UUID — also the idempotency key on webhook_events.
            'id' => ['required', 'string'],
            'registration_number' => ['required', 'string'],
            'company_folder_name' => ['required', 'string'],

            // Flat field bag with company + per-shareholder data.
            'fields' => ['required', 'array', $this->shareholdersComplete()],

            // Company-level fields the acta render needs (no silent defaults anymore).
            'fields.companyName' => ['required', 'string'],
            'fields.companyType' => ['required', 'string'],
            // El objeto social es el mismo para todas: NO se pide en el payload; si no
            // viene, el parser aplica el estándar (SingapurSubmissionParser::DEFAULT_COMPANY_OBJECT).
            'fields.companyObject' => ['nullable', 'string'],
            'fields.capitalSocial' => ['required', 'numeric'],
            'fields.shareholderCount' => ['required', 'integer', 'min:1'],

            // Denomination assigned from our pool (the relay echoes the id back).
            'fields.denominationPoolId' => ['required'],

            // Optional pre-rendered acta (base64 string or {content,...} object).
            'incorporation_deed' => ['nullable'],

            // Inline document attachments (the file binary travels in `content`).
            'files' => ['nullable', 'array'],
            'files.*.field' => ['required', 'string'],
            'files.*.original_name' => ['required', 'string'],
            'files.*.relay_name' => ['required', 'string'],
            'files.*.content_type' => ['required', 'string'],
            'files.*.size' => ['required'],
            'files.*.content' => ['nullable', 'string'],
        ], $this->singapurValidationMessages());

        $eventId = $validated['id'];

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
     * Closure rule that validates every declared shareholder carries the render-critical
     * fields: a name, a positive share percentage, an email (needed for DocuSign) and a
     * nationality. Indexed keys (…{i}) cannot be expressed with Laravel wildcards, so the
     * check runs over `fields` directly and reports each gap with the exact field name.
     */
    private function shareholdersComplete(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            $count = (int) ($value['shareholderCount'] ?? 0);

            for ($i = 1; $i <= $count; $i++) {
                $name = $value["naturalShareholderNameEs{$i}"]
                    ?? $value["naturalShareholderName{$i}"]
                    ?? $value["juridicaShareholderName{$i}"]
                    ?? null;

                if (blank($name)) {
                    $fail("El socio {$i} no tiene nombre (naturalShareholderName{$i}).");
                }

                if (! isset($value["naturalSharePercentage{$i}"]) || (float) $value["naturalSharePercentage{$i}"] <= 0) {
                    $fail("El socio {$i} no tiene porcentaje de participación válido (naturalSharePercentage{$i}).");
                }

                if (blank($value["naturalShareholderEmail{$i}"] ?? null)) {
                    $fail("El socio {$i} no tiene correo (naturalShareholderEmail{$i}); es obligatorio para la firma.");
                }

                $nationality = $value["naturalNationality{$i}"] ?? $value["naturalOtherNationality{$i}"] ?? null;

                if (blank($nationality)) {
                    $fail("El socio {$i} no tiene nacionalidad (naturalNationality{$i}).");
                }
            }
        };
    }

    /**
     * Human-readable Spanish messages for the Singapur submission validation.
     *
     * @return array<string, string>
     */
    private function singapurValidationMessages(): array
    {
        return [
            'fields.companyName.required' => 'Falta companyName (razón social base).',
            'fields.companyType.required' => 'Falta companyType (sa, srl o sapi).',
            'fields.capitalSocial.required' => 'Falta capitalSocial (capital social).',
            'fields.capitalSocial.numeric' => 'capitalSocial debe ser numérico.',
            'fields.shareholderCount.required' => 'Falta shareholderCount (número de socios).',
            'fields.shareholderCount.min' => 'Debe declararse al menos un socio (shareholderCount ≥ 1).',
            'fields.denominationPoolId.required' => 'Falta denominationPoolId (denominación asignada del pool).',
        ];
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
