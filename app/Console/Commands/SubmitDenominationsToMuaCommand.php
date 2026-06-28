<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\LegalNameStatusEnum;
use App\Models\LegalName;
use App\Services\Mua\MuaSubmissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Cron command that picks up denomination proposals stuck in WAIT status and
 * attempts to submit them to the MUA bot when conditions allow.
 *
 * Scheduled to run every minute. On each run MuaSubmissionService checks:
 *   1. Is it Mon–Fri 09:00–16:00 CDMX? (SE portal hours)
 *   2. Is there a FIEL account with fewer than 5 submissions today?
 *
 * If either condition fails the command exits immediately — denominations stay
 * in WAIT and will be retried on the next run. This makes the command safe to
 * schedule continuously without adding noise outside the submission window.
 *
 * Immediate submissions from the Singapur webhook are handled by
 * SubmitLegalNameToMuaJob; this command is the fallback for denominations that
 * arrived outside business hours or when all FIELs were at capacity.
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
    protected $description = 'Submit pending denomination proposals (status=wait) to the MUA bot when business hours and FIEL availability allow';

    /**
     * Execute the console command.
     *
     * Fetches all WAIT denominations not yet assigned to a FIEL, checks
     * business hours and FIEL capacity via MuaSubmissionService, and submits
     * each one. Stops early if the service signals that no submission is
     * possible (out of hours or no FIEL available).
     *
     * @param  MuaSubmissionService  $muaSubmissionService  Injected MUA submission service.
     * @return int Command::SUCCESS or Command::FAILURE
     */
    public function handle(MuaSubmissionService $muaSubmissionService): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        // Fast exit: skip entirely outside business hours.
        if (! $muaSubmissionService->isBusinessHours()) {
            $this->line('Outside SE business hours — nothing to do.');

            return Command::SUCCESS;
        }

        // Fast exit: no FIEL with daily capacity.
        if ($muaSubmissionService->findAvailableFiel() === null) {
            $this->warn('No FIEL accounts with available daily capacity — nothing to submit.');
            Log::warning('mua:submit — all FIEL accounts at daily limit.');

            return Command::SUCCESS;
        }

        $pendingNames = LegalName::where('status', LegalNameStatusEnum::WAIT->value)
            ->whereNull('soldado_id')
            ->with('registration')
            ->get();

        if ($pendingNames->isEmpty()) {
            $this->info('No denominations waiting — nothing to do.');

            return Command::SUCCESS;
        }

        $this->info("Found {$pendingNames->count()} denomination(s) to submit.");

        foreach ($pendingNames as $legalName) {
            $this->line("-> [{$legalName->name}]");

            if ($isDryRun) {
                continue;
            }

            try {
                $submitted = $muaSubmissionService->trySubmit($legalName);

                if (! $submitted) {
                    // Conditions changed mid-loop (e.g. last FIEL hit its limit).
                    $this->warn('  Deferred — conditions no longer met. Stopping.');
                    break;
                }

                $this->info('  Submitted.');
            } catch (\Throwable $th) {
                Log::error('mua:submit — failed to submit denomination.', [
                    'legal_name_id' => $legalName->id,
                    'name' => $legalName->name,
                    'exception' => $th->getMessage(),
                ]);

                $this->error("  Failed: {$th->getMessage()}");
            }
        }

        $this->info('Done.');

        return Command::SUCCESS;
    }
}
