<?php

declare(strict_types=1);

namespace App\Services\Mua;

use App\Enums\LegalNameEventTypeEnum;
use App\Enums\LegalNameStatusEnum;
use App\Models\LegalName;
use App\Models\Soldado;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the submission of denomination proposals to the MUA bot microservice.
 *
 * Enforces two pre-conditions before submitting:
 *   1. Business hours gate — the SE portal only accepts requests Mon–Fri 09:00–16:00 CDMX.
 *   2. FIEL availability — ONE in-process denomination per FIEL account at a time.
 *      That is the SE's own cap, per ACCOUNT (RFC), not per fedatario and not a daily
 *      quota: the slot frees only when that denomination is approved or rejected.
 *
 * If either condition is not met, trySubmit() returns false and the denomination stays
 * in WAIT status so the next dispatch picks it up.
 *
 * When both are satisfied the service CLAIMS a free FIEL — selection and the
 * SUBMITTING mark happen together under a lock, so two concurrent submissions can
 * never hand the same account two denominations — and fires the HTTP call to the bot.
 */
class MuaSubmissionService
{
    /**
     * Timezone used for all business-hours and daily-limit calculations.
     */
    private const TIMEZONE = 'America/Mexico_City';

    /**
     * First hour (inclusive) of the SE submission window.
     */
    private const BUSINESS_START_HOUR = 9;

    /**
     * Last hour (exclusive) of the SE submission window.
     */
    private const BUSINESS_END_HOUR = 16;

    /**
     * Canonical company_type slugs accepted by the MUA bot.
     *
     * The bot is the single source of truth for the slug → SE régimen translation
     * (sa → 19, srl → 13, sapi → 89). Nexum only validates and forwards the slug;
     * it intentionally keeps no copy of the numeric régimen catalog.
     *
     * @var list<string>
     */
    private const VALID_COMPANY_TYPES = ['sa', 'srl', 'sapi'];

    /**
     * Fixed SE entity code for Sinaloa — the state where Nexum's notary operates.
     */
    public const NEXUM_ENTIDAD = '25';

    /**
     * Fixed fedatario ID for Nexum's notary — Notaría 248 in Sinaloa.
     */
    public const NEXUM_FEDATARIO_ID = '311697';

    /**
     * Normalize a stored company type into the canonical slug the MUA bot expects.
     *
     * Registrations persist the display label ("SA de CV", "SRL de CV", "SAPI de CV")
     * because the acta constitutiva renders it verbatim, but the bot keys its régimen
     * catalog on the bare slug. This strips the "… de CV" suffix and lower-cases the
     * value so both a label and an already-bare slug resolve to one of the supported
     * types, and rejects anything else so a malformed value never reaches the bot.
     *
     * @param  string  $companyType  The stored company type (display label or slug).
     * @return string The canonical slug: 'sa', 'srl', or 'sapi'.
     *
     * @throws \InvalidArgumentException When the value is not one of the supported types.
     */
    public function resolveCompanyTypeSlug(string $companyType): string
    {
        $slug = strtolower(trim($companyType));
        $slug = trim((string) preg_replace('/\s+de\s+cv$/', '', $slug));

        if (! in_array($slug, self::VALID_COMPANY_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Unsupported company_type [{$companyType}] — expected one of: "
                .implode(', ', self::VALID_COMPANY_TYPES).'.'
            );
        }

        return $slug;
    }

    /**
     * Attempt to submit a denomination to the MUA bot immediately.
     *
     * Returns true when the submission was dispatched, false when deferred
     * because the business-hours gate or FIEL availability check failed.
     *
     * @param  LegalName  $legalName  The denomination to submit.
     * @return bool Whether the denomination was submitted (true) or deferred (false).
     *
     * @throws \InvalidArgumentException When the registration company_type is unsupported.
     * @throws \RuntimeException When the assigned FIEL account is missing credentials.
     * @throws RequestException When the bot HTTP call fails.
     */
    public function trySubmit(LegalName $legalName): bool
    {
        if (! $this->isBusinessHours()) {
            Log::info('MuaSubmissionService: outside business hours — denomination deferred.', [
                'legal_name_id' => $legalName->id,
                'name' => $legalName->name,
                'local_time' => Carbon::now(self::TIMEZONE)->toDateTimeString(),
            ]);

            return false;
        }

        $soldado = $this->claimFielFor($legalName);

        if ($soldado === null) {
            // Every FIEL is busy: the SE allows a single in-process request per
            // account, so the denomination waits until one frees up (its current
            // denomination is approved or rejected).
            $legalName->recordEvent(
                LegalNameEventTypeEnum::DEFERRED,
                'Sin fedatario disponible: todas las FIEL tienen una denominación en proceso en la SE. Se reintentará cuando se libere una.',
            );

            Log::warning('MuaSubmissionService: all FIELs occupied with an in-process denomination — deferred.', [
                'legal_name_id' => $legalName->id,
                'name' => $legalName->name,
            ]);

            return false;
        }

        $this->submitToBot($legalName, $soldado);

        return true;
    }

    /**
     * Determine whether the current moment falls within SE business hours.
     *
     * The SE portal accepts denomination submissions Mon–Fri 09:00–16:00 CDMX only.
     *
     * @return bool True when the current CDMX time is within the submission window.
     */
    public function isBusinessHours(): bool
    {
        $now = Carbon::now(self::TIMEZONE);

        // Carbon::dayOfWeek: 0 = Sunday, 1 = Monday, 5 = Friday, 6 = Saturday.
        if ($now->dayOfWeek === 0 || $now->dayOfWeek === 6) {
            return false;
        }

        return $now->hour >= self::BUSINESS_START_HOUR
            && $now->hour < self::BUSINESS_END_HOUR;
    }

    /**
     * Atomically claim a free FIEL for this denomination.
     *
     * Selecting a FIEL and marking the denomination SUBMITTING must happen together.
     * Occupancy is derived from status, so between a bare findAvailableFiel() and the
     * status write there is a window where a second submission sees the same account
     * as free and claims it too — handing one RFC two denominations, which the SE
     * refuses outright. That window is real now that the bulk button dispatches
     * every submission to the queue at once.
     *
     * The lock is held only for the claim; the slow HTTP call to the bot happens
     * outside it, so parallel submissions still overlap where it matters.
     *
     * @param  LegalName  $legalName  The denomination to assign a FIEL to.
     * @return Soldado|null The claimed FIEL, or null when every one is occupied.
     */
    private function claimFielFor(LegalName $legalName): ?Soldado
    {
        return Cache::lock('mua:fiel-claim', 15)->block(20, function () use ($legalName): ?Soldado {
            $soldado = $this->findAvailableFiel();

            if ($soldado === null) {
                return null;
            }

            // Marking SUBMITTING is what occupies the account's slot, so it has to
            // land before the lock is released.
            $legalName->update([
                'status' => LegalNameStatusEnum::SUBMITTING->value,
                'soldado_id' => $soldado->id,
                'submitted_at' => now(),
            ]);

            return $soldado;
        });
    }

    /**
     * Count the FIELs usable for MUA right now, split into total and free.
     *
     * "Ready" means an active soldado flagged for MUA holding all three credentials.
     * "Free" means ready AND not already holding an in-process denomination. The
     * operator needs both numbers to tell "every soldier is busy working" apart from
     * "we only ever configured two soldiers".
     *
     * @return array{ready:int, free:int, busy:int, free_names:list<string>}
     */
    public function fielAvailability(): array
    {
        $ready = Soldado::where('available_for_mua', true)
            ->where('is_active', true)
            ->get()
            ->filter(fn (Soldado $soldado): bool => $soldado->isReadyForMua());

        $free = $ready->reject(fn (Soldado $soldado): bool => $this->hasInProcessDenomination($soldado));

        return [
            'ready' => $ready->count(),
            'free' => $free->count(),
            'busy' => $ready->count() - $free->count(),
            'free_names' => $free->pluck('name')->values()->all(),
        ];
    }

    /**
     * Find a soldado FIEL that is free to take a new denomination.
     *
     * The SE allows only ONE in-process request per ACCOUNT (RFC/e.firma) at a
     * time — not per fedatario; the notary is the same for all of them. So a FIEL
     * is eligible only when it is not already holding an in-process denomination. Filters by: MUA capability, active flag, all three
     * credentials present, and no denomination currently in process.
     *
     * Candidates are tried LEAST-RECENTLY-USED first rather than oldest-created
     * first. Picking by creation order meant the same FIEL was chosen for every
     * dispatch, so one account that the SE refuses absorbed attempt after attempt
     * while healthy FIELs sat idle. Rotating spreads the load and lets a failed
     * dispatch fall through to a different account on the next try.
     *
     * @return Soldado|null A free soldado FIEL, or null if none are available.
     */
    public function findAvailableFiel(): ?Soldado
    {
        return Soldado::where('available_for_mua', true)
            ->where('is_active', true)
            ->withMax('legalNames', 'submitted_at')
            ->orderByRaw('legal_names_max_submitted_at IS NOT NULL')
            ->orderBy('legal_names_max_submitted_at')
            ->get()
            ->first(function (Soldado $soldado): bool {
                return $soldado->isReadyForMua()
                    && ! $this->hasInProcessDenomination($soldado);
            });
    }

    /**
     * Determine whether at least one COMPLETE FIEL exists at all — regardless of
     * whether it is busy right now.
     *
     * "Complete" means an active soldado flagged for MUA that holds all three
     * credentials (certificate, private_key, password). This is the check to run
     * before sending: if it returns false there is simply no usable FIEL and the
     * denominations cannot be registered until one is configured.
     *
     * @return bool True when at least one soldado has a complete FIEL.
     */
    public function hasAnyCompleteFiel(): bool
    {
        return Soldado::where('available_for_mua', true)
            ->where('is_active', true)
            ->get()
            ->contains(fn (Soldado $soldado): bool => $soldado->isReadyForMua());
    }

    /**
     * Human-readable reason a submission cannot happen right now, or null if it can.
     *
     * Distinguishes the three deferral causes so the dashboard can show an accurate
     * alert: outside business hours, no complete FIEL configured at all, or every
     * complete FIEL is currently occupied with an in-process denomination.
     *
     * @return string|null The reason, or null when a FIEL is available to submit now.
     */
    public function unavailabilityReason(): ?string
    {
        if (! $this->isBusinessHours()) {
            return 'Fuera del horario hábil de la SE (Lun–Vie 09:00–16:00 CDMX).';
        }

        if (! $this->hasAnyCompleteFiel()) {
            return 'No hay ninguna FIEL completa disponible. Cada FIEL necesita sus tres elementos (certificado .cer, llave privada .key y contraseña); revisa el módulo de Soldados.';
        }

        if ($this->findAvailableFiel() === null) {
            return 'Todas las FIEL están ocupadas con una denominación en proceso en la SE. Se reintentará cuando se libere una.';
        }

        return null;
    }

    /**
     * Determine whether the soldado's FIEL is currently occupied.
     *
     * A FIEL holding a denomination in a non-terminal state — SUBMITTING, PENDING
     * or PROCESS — cannot take another one (the SE allows a single in-process
     * request per ACCOUNT). The slot frees only when that denomination becomes
     * APPROVED or REJECTED.
     *
     * Caveat this cannot see: the SE counts EVERY request on that account, including
     * ones made before Nexum existed. An account carrying old unresolved requests
     * looks free here and is refused by the portal. That is what `deferred` handles
     * — the bot reports the portal's own count and the FIEL leaves the rotation.
     *
     * @param  Soldado  $soldado  The soldado FIEL to check.
     * @return bool True when the FIEL already has an in-process denomination.
     */
    public function hasInProcessDenomination(Soldado $soldado): bool
    {
        return LegalName::where('soldado_id', $soldado->id)
            ->whereIn('status', [
                LegalNameStatusEnum::SUBMITTING->value,
                LegalNameStatusEnum::PENDING->value,
                LegalNameStatusEnum::PROCESS->value,
            ])
            ->exists();
    }

    /**
     * Build the bot payload and fire the HTTP submission request.
     *
     * Assigns the FIEL account to the denomination, updates status to PENDING,
     * and records submitted_at. The bot will call the webhook callback when the
     * SE resolves the denomination.
     *
     * @param  LegalName  $legalName  The denomination to submit.
     * @param  Soldado  $soldado  The soldado whose FIEL credentials will be used.
     *
     * @throws \InvalidArgumentException When the registration company_type is unsupported.
     * @throws \RuntimeException When any of the three FIEL credentials are missing.
     * @throws RequestException When the bot returns a non-2xx response.
     */
    private function submitToBot(LegalName $legalName, Soldado $soldado): void
    {
        $cert = $soldado->getCredential('certificate');
        $keyPem = $soldado->getCredential('private_key');
        $password = $soldado->getCredential('password');

        if (! $cert || ! $keyPem || ! $password) {
            throw new \RuntimeException(
                "Soldado [{$soldado->id}] is missing one or more FIEL credentials."
            );
        }

        $legalName->load('registration');
        // Registration-bound names take their type from the expedient; standalone
        // pool names carry their own company_type column.
        $rawCompanyType = $legalName->registration->company_type ?? $legalName->company_type ?? '';
        $companyType = $this->resolveCompanyTypeSlug((string) $rawCompanyType);

        $botUrl = rtrim((string) config('services.mua_bot.url'), '/');
        $apiKey = (string) config('services.mua_bot.api_key');

        // The denomination is ALREADY SUBMITTING with this FIEL assigned — claimFielFor()
        // did it under the lock before this method ran. That ordering is deliberate: the
        // bot works ~1 min and reports through the webhook, which only advances from
        // SUBMITTING/PENDING, so a callback arriving after our read timeout below would
        // be discarded if the name were still in WAIT.
        $legalName->recordEvent(
            LegalNameEventTypeEnum::SUBMIT_DISPATCHED,
            "Solicitud enviada al bot con la FIEL «{$soldado->name}». Esperando confirmación de la SE.",
            [
                'soldado_id' => $soldado->id,
                'soldado_name' => $soldado->name,
                'company_type' => $companyType,
            ],
        );

        try {
            // We only need the bot to RECEIVE the request. It then works ~1 min
            // (login + capture + sign) and reports the real outcome via the webhook
            // callback — the source of truth. Blocking the PHP process up to 180s
            // pinned a worker/web request for minutes and, on a 1 GB instance, forced
            // Horizon to spin up extra workers → RAM spikes. A short timeout frees the
            // process fast; the ConnectionException below is the expected, safe path
            // (status stays SUBMITTING and the webhook finalizes it).
            Http::connectTimeout(10)
                ->timeout(30)
                ->withHeader('X-Bot-Api-Key', $apiKey)
                ->post("{$botUrl}/submit", [
                    'legal_name_id' => $legalName->id,
                    'denomination' => $legalName->name,
                    'company_type' => $companyType,
                    'entidad' => self::NEXUM_ENTIDAD,
                    'fedatario_id' => self::NEXUM_FEDATARIO_ID,
                    'cert_base64' => $cert,
                    'key_base64' => $keyPem,
                    'password' => $password,
                ])
                ->throw();

            Log::info('MuaSubmissionService: denomination dispatched to MUA bot.', [
                'legal_name_id' => $legalName->id,
                'name' => $legalName->name,
                'soldado_id' => $soldado->id,
                'company_type' => $companyType,
            ]);
        } catch (ConnectionException $exception) {
            // A read timeout / dropped connection is EXPECTED: the synchronous bot
            // can run longer than our timeout. It keeps working and reports via the
            // webhook (the source of truth), so we leave the name SUBMITTING.
            Log::warning('MuaSubmissionService: bot /submit timed out — leaving SUBMITTING, webhook will finalize.', [
                'legal_name_id' => $legalName->id,
                'name' => $legalName->name,
                'error' => $exception->getMessage(),
            ]);
        } catch (RequestException $exception) {
            // The bot replied non-2xx: it rejected the dispatch outright and no
            // webhook will follow. Return the name to the queue for a manual resend.
            $legalName->update([
                'status' => LegalNameStatusEnum::WAIT,
                'soldado_id' => null,
                'submitted_at' => null,
            ]);

            $legalName->recordEvent(
                LegalNameEventTypeEnum::SUBMISSION_FAILED,
                'El bot rechazó el envío. Regresó a la cola.',
                ['error' => $exception->getMessage()],
            );

            throw $exception;
        }
    }
}
