<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V3;

use App\Enums\AppointmentStatusEnum;
use App\Enums\AppointmentTypeEnum;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exposes SAT appointments awaiting FORMING (virtual queue) to the nexum-citas-sat bot.
 *
 * Phase 1 of the bot's cycle: the bot polls GET /api/v3/sat-bot/pending-forming to learn
 * which appointments it must form in the SAT virtual queue, forms them, and reports back
 * with status "formed" via the callback. Phase 2 (reviewing formed appointments) lives in
 * SatBotReviewController. Auth: shared X-Bot-Api-Key header.
 *
 * Nexum owns the email pool: this endpoint ASSIGNS and LOCKS a pool address per
 * appointment before handing it to the bot, so the SAT token lands somewhere the bot can
 * read. The assignment is idempotent — an appointment that already carries an alias keeps
 * it across retries instead of draining the pool.
 *
 * The bot decides the SAT module (CDMX branches); Nexum only states the entidad.
 */
class SatBotFormingController extends Controller
{
    /**
     * SAT entidad for Ciudad de México, where appointments are formed.
     *
     * It is 10 in the SAT's own catalog — NOT 09, which is the INEGI code. Verified
     * against the portal's POST /api/filtros/servicio response.
     */
    private const NEXUM_ENTIDAD = '10';

    /**
     * Return appointments in `pending_forming`, each with a locked pool address.
     *
     * Only appointments with a soldado assigned are returned: the soldado is the person
     * who physically attends, and the SAT requires their data to form the queue entry.
     * Appointments for which no pool address can be locked are skipped (and logged)
     * rather than handed over without a mailbox to read the token from.
     *
     * @param  Request  $request  Incoming request with X-Bot-Api-Key header.
     */
    public function index(Request $request): JsonResponse
    {
        $apiKey = $request->header('X-Bot-Api-Key');

        if (! $apiKey || ! hash_equals((string) config('services.sat_bot.api_key'), (string) $apiKey)) {
            return response()->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $appointments = Appointment::where('status', AppointmentStatusEnum::PENDING_FORMING->value)
            ->whereNotNull('soldado_id')
            ->with(['registration.primaryLegalName', 'soldado'])
            ->get();

        $data = [];

        foreach ($appointments as $appointment) {
            $alias = $this->assignAlias($appointment);

            if ($alias === null) {
                Log::warning('SAT bot forming: no free pool address available.', [
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
                // Sucursal elegida por el equipo; null = el bot recorre su propia lista.
                'preferred_module' => $appointment->preferred_module,
            ];
        }

        return response()->json(['data' => $data], Response::HTTP_OK);
    }

    /**
     * Assign (or reuse) a locked pool address for this appointment.
     *
     * Reusing an already-assigned alias keeps the endpoint idempotent: a retried
     * appointment does not consume a second address. Locking happens inside a
     * transaction with a row lock so two concurrent polls cannot take the same address.
     *
     * @param  Appointment  $appointment  The appointment to give a mailbox to.
     * @return string|null The pool address, or null when the pool is exhausted.
     */
    private function assignAlias(Appointment $appointment): ?string
    {
        if (filled($appointment->email_alias)) {
            return $appointment->email_alias;
        }

        return DB::transaction(function () use ($appointment): ?string {
            $email = AppointmentEmail::where('is_free', true)
                ->lockForUpdate()
                ->orderBy('address')
                ->first();

            if ($email === null) {
                return null;
            }

            $email->update(['is_free' => false]);
            $appointment->update(['email_alias' => $email->address]);

            Log::info('SAT bot forming: pool address assigned.', [
                'appointment_id' => $appointment->id,
                'email_alias' => $email->address,
            ]);

            return $email->address;
        });
    }
}
