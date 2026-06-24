<?php

declare(strict_types=1);

namespace App\Services\Mua;

use App\Enums\LegalNameStatusEnum;
use App\Models\LegalName;
use App\Models\MuaAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the submission of denomination proposals to the MUA bot microservice.
 *
 * Enforces two pre-conditions before submitting:
 *   1. Business hours gate — the SE portal only accepts requests Mon–Fri 09:00–16:00 CDMX.
 *   2. FIEL availability — at most 5 denominations per FIEL account per calendar day (CDMX).
 *
 * If either condition is not met, trySubmit() returns false and the denomination stays
 * in WAIT status so the cron scheduler picks it up in the next window.
 *
 * When both conditions are satisfied, the service assigns the FIEL with the lowest
 * daily usage, builds the bot payload with all required fields (including the fixed
 * Nexum notary: Sinaloa / Notaría 248), and fires the HTTP call.
 */
class MuaSubmissionService
{
    /**
     * Maximum denominations a single FIEL account may submit per calendar day.
     */
    private const DAILY_LIMIT = 5;

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
    private const NEXUM_ENTIDAD = '25';

    /**
     * Fixed fedatario ID for Nexum's notary — Notaría 248 in Sinaloa.
     */
    private const NEXUM_FEDATARIO_ID = '311697';

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
     *
     * @return string The canonical slug: 'sa', 'srl', or 'sapi'.
     *
     * @throws \InvalidArgumentException  When the value is not one of the supported types.
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
     *
     * @return bool Whether the denomination was submitted (true) or deferred (false).
     *
     * @throws \InvalidArgumentException  When the registration company_type is unsupported.
     * @throws \RuntimeException  When the assigned FIEL account is missing credentials.
     * @throws \Illuminate\Http\Client\RequestException  When the bot HTTP call fails.
     */
    public function trySubmit(LegalName $legalName): bool
    {
        if (! $this->isBusinessHours()) {
            Log::info('MuaSubmissionService: outside business hours — denomination deferred.', [
                'legal_name_id' => $legalName->id,
                'name'          => $legalName->name,
                'local_time'    => Carbon::now(self::TIMEZONE)->toDateTimeString(),
            ]);

            return false;
        }

        $account = $this->findAvailableFiel();

        if ($account === null) {
            Log::warning('MuaSubmissionService: no FIEL with available daily capacity — denomination deferred.', [
                'legal_name_id' => $legalName->id,
                'name'          => $legalName->name,
            ]);

            return false;
        }

        $this->submitToBot($legalName, $account);

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
     * Find the FIEL account with the lowest daily usage that still has capacity.
     *
     * Filters accounts by: active flag, all three credentials present, and fewer
     * than DAILY_LIMIT submissions today (CDMX calendar day). Returns the one with
     * the fewest submissions so load is distributed evenly across accounts.
     *
     * @return MuaAccount|null The best available account, or null if none qualify.
     */
    public function findAvailableFiel(): ?MuaAccount
    {
        return MuaAccount::where('is_active', true)
            ->get()
            ->filter(function (MuaAccount $account): bool {
                return $account->isReady()
                    && $this->dailySubmissionsCount($account) < self::DAILY_LIMIT;
            })
            ->sortBy(fn (MuaAccount $account): int => $this->dailySubmissionsCount($account))
            ->first();
    }

    /**
     * Count how many denominations this FIEL account has submitted today (CDMX time).
     *
     * @param  MuaAccount  $account  The FIEL account to check.
     *
     * @return int Number of submissions made since midnight CDMX today.
     */
    public function dailySubmissionsCount(MuaAccount $account): int
    {
        $todayStart = Carbon::now(self::TIMEZONE)->startOfDay()->utc();
        $todayEnd   = Carbon::now(self::TIMEZONE)->endOfDay()->utc();

        return LegalName::where('mua_account_id', $account->id)
            ->whereBetween('submitted_at', [$todayStart, $todayEnd])
            ->count();
    }

    /**
     * Build the bot payload and fire the HTTP submission request.
     *
     * Assigns the FIEL account to the denomination, updates status to PENDING,
     * and records submitted_at. The bot will call the webhook callback when the
     * SE resolves the denomination.
     *
     * @param  LegalName   $legalName  The denomination to submit.
     * @param  MuaAccount  $account    The FIEL account whose credentials will be used.
     *
     * @return void
     *
     * @throws \InvalidArgumentException  When the registration company_type is unsupported.
     * @throws \RuntimeException  When any of the three FIEL credentials are missing.
     * @throws \Illuminate\Http\Client\RequestException  When the bot returns a non-2xx response.
     */
    private function submitToBot(LegalName $legalName, MuaAccount $account): void
    {
        $cert     = $account->getCredential('certificate');
        $keyPem   = $account->getCredential('private_key');
        $password = $account->getCredential('password');

        if (! $cert || ! $keyPem || ! $password) {
            throw new \RuntimeException(
                "MuaAccount [{$account->id}] is missing one or more FIEL credentials."
            );
        }

        $legalName->load('registration');
        $companyType = $this->resolveCompanyTypeSlug((string) ($legalName->registration->company_type ?? ''));

        $botUrl = rtrim((string) config('services.mua_bot.url'), '/');
        $apiKey = (string) config('services.mua_bot.api_key');

        Http::timeout(30)
            ->withHeader('X-Bot-Api-Key', $apiKey)
            ->post("{$botUrl}/submit", [
                'legal_name_id' => $legalName->id,
                'denomination'  => $legalName->name,
                'company_type'  => $companyType,
                'entidad'       => self::NEXUM_ENTIDAD,
                'fedatario_id'  => self::NEXUM_FEDATARIO_ID,
                'cert_base64'   => $cert,
                'key_base64'    => $keyPem,
                'password'      => $password,
            ])
            ->throw();

        $legalName->update([
            'status'         => LegalNameStatusEnum::PENDING,
            'mua_account_id' => $account->id,
            'submitted_at'   => now(),
        ]);

        Log::info('MuaSubmissionService: denomination submitted to MUA bot.', [
            'legal_name_id'  => $legalName->id,
            'name'           => $legalName->name,
            'mua_account_id' => $account->id,
            'company_type'   => $companyType,
        ]);
    }
}
