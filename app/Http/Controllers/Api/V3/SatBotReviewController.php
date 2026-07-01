<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V3;

use App\Enums\AppointmentStatusEnum;
use App\Enums\AppointmentTypeEnum;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exposes FORMED SAT appointments (awaiting review) to the nexum-citas-sat bot.
 *
 * The team forms each appointment MANUALLY at the SAT portal and marks it "formada"
 * (choosing the pool email used). The bot then polls GET /api/v3/sat-bot/pending-review
 * to learn which formed appointments to check, reads the SAT status, and reports back via
 * the callback when a slot is assigned. Auth: shared X-Bot-Api-Key header.
 *
 * This does NOT reserve/schedule anything — forming is manual. It only lists what the bot
 * should monitor. Mirrors the mua:poll pattern.
 */
class SatBotReviewController extends Controller
{
    /**
     * Fixed SAT entidad federativa — Sinaloa, where Nexum's notary operates.
     */
    private const NEXUM_ENTIDAD = '25';

    /**
     * Return FORMED appointments (with an assigned email alias) for the bot to review.
     *
     * Only appointments the team already formed (status = formed) and that carry the pool
     * email used to receive the token are returned — the bot needs both to check status.
     *
     * @param  Request  $request  Incoming request with X-Bot-Api-Key header.
     */
    public function index(Request $request): JsonResponse
    {
        $apiKey = $request->header('X-Bot-Api-Key');

        if (! $apiKey || ! hash_equals((string) config('services.sat_bot.api_key'), (string) $apiKey)) {
            return response()->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $appointments = Appointment::where('status', AppointmentStatusEnum::FORMED->value)
            ->whereNotNull('email_alias')
            ->whereNotNull('soldado_id')
            ->with(['registration.primaryLegalName', 'soldado'])
            ->get();

        $data = $appointments->map(function (Appointment $appointment): array {
            $registration = $appointment->registration;
            $soldado = $appointment->soldado;

            return [
                'appointment_id' => $appointment->id,
                'type' => $appointment->type->value,
                'sat_service' => $appointment->type === AppointmentTypeEnum::RFC ? 'PM' : 'E',
                'company' => [
                    'registration_id' => $registration?->id,
                    'rfc' => $registration?->rfc,
                    'razon_social' => $registration?->primaryLegalName?->name
                        ?? $registration?->singapur_folder_name,
                ],
                'soldado' => [
                    'id' => $soldado?->id,
                    'name' => $soldado?->name,
                    'curp' => $soldado?->curp,
                    'rfc' => $soldado?->rfc,
                    'email' => $soldado?->email,
                    'phone' => $soldado?->phone,
                ],
                'email_alias' => $appointment->email_alias,
                'entidad' => self::NEXUM_ENTIDAD,
                'formed_at' => $appointment->formed_at?->toIso8601String(),
                'last_review_at' => $appointment->last_review_at?->toIso8601String(),
            ];
        })->all();

        return response()->json(['data' => $data], Response::HTTP_OK);
    }
}
