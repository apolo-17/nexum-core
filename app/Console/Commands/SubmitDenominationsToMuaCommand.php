<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\LegalNameStatusEnum;
use App\Models\LegalName;
use App\Models\MuaAccount;
use Illuminate\Console\Command;
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
     * @return int  Command::SUCCESS or Command::FAILURE
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
                    'status'          => LegalNameStatusEnum::PENDING->value,
                    'mua_account_id'  => $account->id,
                    'submitted_at'    => now(),
                ]);

                $account->increment('active_submissions');

                Log::info('Denomination submitted to MUA.', [
                    'legal_name_id'  => $legalName->id,
                    'name'           => $legalName->name,
                    'mua_account_id' => $account->id,
                ]);
            } catch (\Throwable $th) {
                Log::error('Failed to submit denomination to MUA.', [
                    'legal_name_id'  => $legalName->id,
                    'name'           => $legalName->name,
                    'mua_account_id' => $account->id,
                    'exception'      => $th->getMessage(),
                ]);

                $this->error("  ✗ Failed: {$th->getMessage()}");
            }

            $accountIndex++;
        }

        $this->info('Done.');

        return Command::SUCCESS;
    }

    /**
     * Perform the actual HTTP submission to the MUA portal.
     *
     * The MUA portal (mua.economia.gob.mx) uses FIEL (e.firma) certificate-based
     * authentication for the reservation flow. This method:
     *   1. Loads the decoded .cer certificate and decrypted .key from the account.
     *   2. Signs the request payload using the private key.
     *   3. POSTs the denomination and company type to the MUA reservation endpoint.
     *
     * IMPORTANT: The exact MUA submission API/flow must be validated against the
     * real portal before this method goes to production. Use --dry-run to test
     * the scheduling and account-selection logic without making live requests.
     *
     * @param  LegalName   $legalName  The denomination to submit.
     * @param  MuaAccount  $account    The FIEL account to authenticate with.
     *
     * @return void
     *
     * @throws \RuntimeException When the submission request fails or is rejected by the SE.
     */
    private function submitToMua(LegalName $legalName, MuaAccount $account): void
    {
        // TODO: Implement actual FIEL-authenticated MUA submission.
        // Steps:
        //   $cert     = base64_decode($account->getCredential('certificate'));
        //   $keyPem   = base64_decode($account->getCredential('private_key'));
        //   $password = $account->getCredential('password');
        //
        //   Use openssl_pkcs12_read or a PHP FIEL library to load the cert + key,
        //   then sign and POST to the MUA reservation endpoint.
        //
        // For now, throw to prevent accidental production submissions.
        throw new \RuntimeException(
            'MUA submission not yet implemented. Run with --dry-run to test scheduling.'
        );
    }
}
