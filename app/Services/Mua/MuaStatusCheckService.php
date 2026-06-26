<?php

declare(strict_types=1);

namespace App\Services\Mua;

use App\Enums\LegalNameEventTypeEnum;
use App\Models\LegalName;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Requests an on-demand status check of a single denomination from the MUA bot.
 *
 * This is an asynchronous trigger, mirroring the submit flow: Nexum POSTs to the
 * bot, the bot acknowledges (HTTP 2xx/202) and then scrapes the SE portal in the
 * background. When it resolves the denomination it calls back the existing
 * MuaBotCallbackController (webhook/mua-bot), which records the approved/rejected
 * (or in_process) outcome on the timeline.
 *
 * BOT CONTRACT (to confirm with the bot maintainer):
 *   POST {MUA_BOT_URL}/status
 *   Header: X-Bot-Api-Key: <MUA_BOT_API_KEY>
 *   Body: {
 *     "legal_name_id": "<ulid>",
 *     "denomination":  "<name>",
 *     "company_type":  "sa|srl|sapi",
 *     "entidad":       "25",
 *     "fedatario_id":  "311697",
 *     "cert_base64":   "<FIEL cert>",
 *     "key_base64":    "<FIEL key>",
 *     "password":      "<FIEL password>"
 *   }
 *   Expected: HTTP 202 (accepted). Resolution is delivered later via webhook/mua-bot.
 */
class MuaStatusCheckService
{
    /**
     * @param  MuaSubmissionService  $muaSubmissionService  Reused for company-type slug resolution.
     */
    public function __construct(
        private readonly MuaSubmissionService $muaSubmissionService,
    ) {}

    /**
     * Dispatch a status-check request for a denomination to the MUA bot.
     *
     * Records a CHECK_REQUESTED event and stamps last_status_check_at on success
     * so the detail view can show the "Consultando…" indicator until the bot
     * callback resolves it. On failure records CHECK_FAILED and rethrows so the
     * caller can surface the error.
     *
     * @param  LegalName  $legalName  The submitted denomination to re-check.
     *
     * @throws \RuntimeException When the denomination is not eligible or its FIEL is missing.
     * @throws RequestException When the bot returns a non-2xx response (e.g. endpoint unavailable).
     */
    public function requestCheck(LegalName $legalName): void
    {
        if (! $legalName->canRequestStatusCheck()) {
            throw new \RuntimeException(
                'La denominación no está en un estado consultable (debe estar enviada con FIEL asignada).'
            );
        }

        // The SE portal blocks its login outside business hours, so a check fired
        // off-hours would only fail at the bot. Gate it here, same window as submit.
        if (! $this->muaSubmissionService->isBusinessHours()) {
            throw new \RuntimeException(
                'Fuera del horario hábil de la SE (Lun–Vie 09:00–16:00 CDMX): el portal no permite iniciar sesión todavía.'
            );
        }

        $account = $legalName->muaAccount;

        $cert = $account?->getCredential('certificate');
        $keyPem = $account?->getCredential('private_key');
        $password = $account?->getCredential('password');

        if (! $cert || ! $keyPem || ! $password) {
            throw new \RuntimeException(
                "La FIEL [{$account?->id}] no tiene credenciales completas para consultar."
            );
        }

        $legalName->load('registration');
        $rawCompanyType = $legalName->registration->company_type ?? $legalName->company_type ?? '';
        $companyType = $this->muaSubmissionService->resolveCompanyTypeSlug((string) $rawCompanyType);

        $botUrl = rtrim((string) config('services.mua_bot.url'), '/');
        $apiKey = (string) config('services.mua_bot.api_key');

        try {
            Http::timeout(30)
                ->withHeader('X-Bot-Api-Key', $apiKey)
                ->post("{$botUrl}/status", [
                    'legal_name_id' => $legalName->id,
                    'denomination' => $legalName->name,
                    'company_type' => $companyType,
                    'entidad' => MuaSubmissionService::NEXUM_ENTIDAD,
                    'fedatario_id' => MuaSubmissionService::NEXUM_FEDATARIO_ID,
                    'cert_base64' => $cert,
                    'key_base64' => $keyPem,
                    'password' => $password,
                ])
                ->throw();
        } catch (\Throwable $exception) {
            $legalName->recordEvent(
                LegalNameEventTypeEnum::CHECK_FAILED,
                'No se pudo solicitar la consulta de estado al bot.',
                ['error' => $exception->getMessage()],
            );

            Log::error('MuaStatusCheckService: status check request failed.', [
                'legal_name_id' => $legalName->id,
                'name' => $legalName->name,
                'exception' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $legalName->update(['last_status_check_at' => now()]);

        $legalName->recordEvent(
            LegalNameEventTypeEnum::CHECK_REQUESTED,
            'Consulta de estado enviada al bot. El resultado llegará por el callback.',
            ['mua_account_id' => $account->id, 'mua_account_name' => $account->name],
        );

        Log::info('MuaStatusCheckService: status check requested.', [
            'legal_name_id' => $legalName->id,
            'name' => $legalName->name,
            'mua_account_id' => $account->id,
        ]);
    }
}
