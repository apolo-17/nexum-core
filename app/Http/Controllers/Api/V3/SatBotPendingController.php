<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V3;

use App\Enums\AppointmentTypeEnum;
use App\Enums\EfirmaAppointmentStatusEnum;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exposes SAT appointments awaiting scheduling to the nexum-citas-sat bot.
 *
 * The bot polls GET /api/v3/sat-bot/pending to learn which appointments to schedule,
 * with the company/soldado data it needs and the email alias (from the pool) it should
 * use to receive the SAT token. Auth: shared X-Bot-Api-Key header.
 *
 * See docs/CONTRACT.md in the nexum-citas-sat repo. Mirrors MuaPendingController.
 */
class SatBotPendingController extends Controller
{
    /**
     * Fixed SAT entidad federativa — Sinaloa, where Nexum's notary operates.
     */
    private const NEXUM_ENTIDAD = '25';

    /**
     * Return appointments in PENDING_SCHEDULING status with an assigned email alias.
     *
     * Only appointments that have a soldado (the person to be queued) are returned.
     * For each, a free pool email is assigned and locked; appointments for which no
     * free alias is available are skipped (and logged) so the bot never gets a half row.
     *
     * @param  Request  $request  Incoming request with X-Bot-Api-Key header.
     */
    public function index(Request $request): JsonResponse
    {
        $apiKey = $request->header('X-Bot-Api-Key');

        if (! $apiKey || ! hash_equals((string) config('services.sat_bot.api_key'), (string) $apiKey)) {
            return response()->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $appointments = Appointment::where('status', EfirmaAppointmentStatusEnum::PENDING_SCHEDULING->value)
            ->whereNotNull('soldado_id')
            ->with(['registration.primaryLegalName', 'soldado'])
            ->get();

        $data = [];

        foreach ($appointments as $appointment) {
            $alias = $appointment->email_alias ?? $this->assignAlias($appointment);

            if ($alias === null) {
                Log::warning('SatBotPendingController: no free pool email for appointment.', [
                    'appointment_id' => $appointment->id,
                ]);

                continue;
            }

            $registration = $appointment->registration;
            $soldado = $appointment->soldado;

            $data[] = [
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
                'email_alias' => $alias,
                'entidad' => self::NEXUM_ENTIDAD,
                'preferred_module' => null,
            ];
        }

        return response()->json(['data' => $data], Response::HTTP_OK);
    }

    /**
     * Assign a free pool email to the appointment and lock it, atomically.
     *
     * Uses a row lock so concurrent polls never hand the same alias to two appointments.
     * Returns the assigned address, or null when the pool is exhausted.
     *
     * @param  Appointment  $appointment  The appointment to assign an alias to.
     */
    private function assignAlias(Appointment $appointment): ?string
    {
        return DB::transaction(function () use ($appointment): ?string {
            $email = AppointmentEmail::where('is_free', true)->lockForUpdate()->first();

            if ($email === null) {
                return null;
            }

            $email->update(['is_free' => false]);
            $appointment->update(['email_alias' => $email->address]);

            return $email->address;
        });
    }
}
