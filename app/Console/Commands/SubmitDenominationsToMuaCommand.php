<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\LegalNameStatusEnum;
use App\Models\LegalName;
use App\Models\MuaAccount;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Picks up denomination proposals in WAIT status and submits them to the MUA portal
 * using an available soldado's FIEL (e.firma) credentials.
 *
 * Scheduled to run every few minutes. Each denomination is assigned to a specific
 * MuaAccount so the polling bot knows which credentials to use when checking its status.
 *
 * NOTE: The actual submission mechanism (HTTP request with FIEL certificate signing)
 * is intentionally separated into MuaSubmissionService so it can be swapped or mocked.
 */
class SubmitDenominationsToMuaCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mua:submit
                            {--dry-run : Log what would be submitted without making actual requests}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Submit pending denomination proposals (status=wait) to the MUA portal using available FIEL accounts';

    /**
     * Execute the console command.
     *
     * Fetches all WAIT denominations, picks an available FIEL account (load-balanced
     * by fewest active submissions), and submits each one. On success the denomination
     * is moved to PENDING; on failure it stays in WAIT for the next run.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $pendingNames = LegalName::where('status', LegalNameStatusEnum::WAIT->value)
            ->whereNull('mua_account_id')
            ->with('registration')
            ->get();

        if ($pendingNames->isEmpty()) {
            $this->info('No denominations waiting for MUA submission.');

            return Command::SUCCESS;
        }

        $this->info("Found {$pendingNames->count()} denomination(s) to submit.");

        $availableAccounts = MuaAccount::where('is_active', true)
            ->orderBy('active_submissions')
            ->get()
            ->filter(fn (MuaAccount $account) => $account->isReady());

        if ($availableAccounts->isEmpty()) {
            $this->error('No FIEL accounts are ready. Check that at least one MuaAccount has all three credentials loaded.');
            Log::error('mua:submit — no ready FIEL accounts available.');

            return Command::FAILURE;
        }

        $accountIndex = 0;
        $totalAccounts = $availableAccounts->count();

        foreach ($pendingNames as $legalName) {
            /** @var MuaAccount $account */
            $account = $availableAccounts->values()->get($accountIndex % $totalAccounts);

            $this->line("→ [{$legalName->name}] → {$account->name} ({$account->rfc})");

            if ($isDryRun) {
                $accountIndex++;

                continue;
            }

            try {
                $this->submitToMua($legalName, $account);

                $legalName->update([
                    'status' => LegalNameStatusEnum::PENDING->value,
                    'mua_account_id' => $account->id,
                    'submitted_at' => now(),
                ]);

                $account->increment('active_submissions');

                Log::info('Denomination submitted to MUA.', [
                    'legal_name_id' => $legalName->id,
                    'name' => $legalName->name,
                    'mua_account_id' => $account->id,
                ]);
            } catch (\Throwable $th) {
                Log::error('Failed to submit denomination to MUA.', [
                    'legal_name_id' => $legalName->id,
                    'name' => $legalName->name,
                    'mua_account_id' => $account->id,
                    'exception' => $th->getMessage(),
                ]);

                $this->error("  ✗ Failed: {$th->getMessage()}");
            }

            $accountIndex++;
        }

        $this->info('Done.');

        return Command::SUCCESS;
    }

    /**
     * Send the denomination to the MUA bot microservice for portal submission.
     *
     * The bot receives the denomination name and the FIEL credentials (base64-encoded
     * .cer and .key files plus the password) and handles all browser automation
     * against the SE portal asynchronously. Laravel is notified of the result
     * later via the POST /api/v3/webhook/mua-bot callback.
     *
     * @param  LegalName  $legalName  The denomination to submit.
     * @param  MuaAccount  $account  The FIEL account whose credentials the bot will use.
     *
     * @throws \RuntimeException When credentials are missing or the bot returns an error.
     * @throws RequestException When the bot HTTP call fails.
     */
    private function submitToMua(LegalName $legalName, MuaAccount $account): void
    {
        $cert = $account->getCredential('certificate');
        $keyPem = $account->getCredential('private_key');
        $password = $account->getCredential('password');

        if (! $cert || ! $keyPem || ! $password) {
            throw new \RuntimeException(
                "MuaAccount [{$account->id}] is missing one or more FIEL credentials."
            );
        }

        $botUrl = rtrim((string) config('services.mua_bot.url'), '/');

        Http::timeout(30)
            ->post("{$botUrl}/submit", [
                'legal_name_id' => $legalName->id,
                'denomination' => $legalName->name,
                'cert_base64' => $cert,
                'key_base64' => $keyPem,
                'password' => $password,
            ])
            ->throw();
    }
}
