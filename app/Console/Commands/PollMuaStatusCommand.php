<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\LegalNameStatusEnum;
use App\Models\LegalName;
use App\Models\Registration;
use App\Notifications\DenominationResolvedNotification;
use App\Services\LegalName\CheckMuaAvailabilityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Polls the MUA portal for the current status of denominations that have been submitted
 * (status = pending or process) and updates them to APPROVED or REJECTED accordingly.
 *
 * When a denomination is approved, notifies the assigned notario so they can
 * advance the registration to the next stage.
 *
 * Scheduled to run every few minutes alongside mua:submit.
 */
class PollMuaStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mua:poll
                            {--dry-run : Log what would be updated without persisting changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Poll MUA portal for status updates on submitted denominations and notify on resolution';

    /**
     * @param  CheckMuaAvailabilityService  $checkMuaAvailabilityService  Reused to query the public MUA endpoint.
     */
    public function __construct(
        private readonly CheckMuaAvailabilityService $checkMuaAvailabilityService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * Queries denominations in PENDING or PROCESS status. For each one it re-checks
     * whether the name now appears in the "authorized" registry (approved) or has been
     * removed/rejected. Transitions status accordingly and notifies when resolved.
     *
     * @return int  Command::SUCCESS or Command::FAILURE
     */
    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $inFlight = LegalName::whereIn('status', [
            LegalNameStatusEnum::PENDING->value,
            LegalNameStatusEnum::PROCESS->value,
        ])
            ->with(['registration.assignedNotario', 'muaAccount'])
            ->get();

        if ($inFlight->isEmpty()) {
            $this->info('No denominations in flight — nothing to poll.');

            return Command::SUCCESS;
        }

        $this->info("Polling {$inFlight->count()} denomination(s)...");

        foreach ($inFlight as $legalName) {
            $this->pollDenomination($legalName, $isDryRun);
        }

        $this->info('Poll complete.');

        return Command::SUCCESS;
    }

    /**
     * Poll MUA for a single denomination and update its status if resolved.
     *
     * Uses the public MUA endpoint to check whether the name now appears in
     * the authorized registry. A name that was NOT available before (i.e., was
     * reserved/submitted) and NOW appears as available = still pending.
     * When the SE registers it as authorized it appears with a `clave_unica_denominacion`.
     *
     * NOTE: The real polling logic requires reading the authorized registry response
     * and extracting the `clave_unica_denominacion` assigned by the SE.
     * This implementation demonstrates the structure; the extraction logic must be
     * completed once the exact MUA response format is confirmed.
     *
     * @param  LegalName  $legalName  Denomination to poll.
     * @param  bool       $isDryRun   When true, skip persistence.
     *
     * @return void
     */
    private function pollDenomination(LegalName $legalName, bool $isDryRun): void
    {
        $this->line("  ↻ [{$legalName->name}] current status: {$legalName->status->value}");

        try {
            $result = $this->queryMuaStatus($legalName->name);

            if ($result === null) {
                $this->warn("  ⚠ MUA unreachable for [{$legalName->name}] — skipping.");

                return;
            }

            ['approved' => $approved, 'clave' => $clave] = $result;

            if ($approved) {
                $this->info("  ✓ APPROVED: {$legalName->name} (clave: {$clave})");

                if (! $isDryRun) {
                    $legalName->update([
                        'status'                  => LegalNameStatusEnum::APPROVED->value,
                        'clave_unica_denominacion' => $clave,
                        'authorization_timestamp'  => now(),
                    ]);

                    // Decrement active submissions counter on the assigned account.
                    if ($legalName->muaAccount) {
                        $legalName->muaAccount->decrement('active_submissions');
                    }

                    $this->notifyNotario($legalName->registration, $legalName->name, true);

                    Log::info('Denomination approved by SE.', [
                        'legal_name_id' => $legalName->id,
                        'name'          => $legalName->name,
                        'clave'         => $clave,
                    ]);
                }
            } elseif ($approved === false && $legalName->status === LegalNameStatusEnum::PROCESS) {
                // Name was in PROCESS but is no longer found — assume rejected.
                $this->warn("  ✗ REJECTED: {$legalName->name}");

                if (! $isDryRun) {
                    $legalName->update([
                        'status'           => LegalNameStatusEnum::REJECTED->value,
                        'rejection_reason' => 'Rechazada por la Secretaría de Economía',
                    ]);

                    if ($legalName->muaAccount) {
                        $legalName->muaAccount->decrement('active_submissions');
                    }

                    $this->notifyNotario($legalName->registration, $legalName->name, false);

                    Log::info('Denomination rejected by SE.', [
                        'legal_name_id' => $legalName->id,
                        'name'          => $legalName->name,
                    ]);
                }
            } else {
                $this->line("  ⏳ Still pending: {$legalName->name}");

                // Move from PENDING to PROCESS if it now appears in the SE queue.
                if ($legalName->status === LegalNameStatusEnum::PENDING && ! $isDryRun) {
                    $legalName->update(['status' => LegalNameStatusEnum::PROCESS->value]);
                }
            }
        } catch (\Throwable $th) {
            Log::error('Error polling MUA for denomination.', [
                'legal_name_id' => $legalName->id,
                'name'          => $legalName->name,
                'exception'     => $th->getMessage(),
            ]);

            $this->error("  ✗ Exception: {$th->getMessage()}");
        }
    }

    /**
     * Query the MUA portal for the current resolution of a denomination.
     *
     * Returns an array with:
     *   - approved (bool): true = SE authorized, false = rejected / not found.
     *   - clave (string|null): the `clave_unica_denominacion` assigned by the SE on approval.
     *
     * Returns null when the MUA portal is unreachable.
     *
     * NOTE: This uses the public consultation endpoint. A dedicated status endpoint
     * with FIEL authentication may return richer data (rejection category, SE timestamp).
     * Extend this method when that endpoint is mapped.
     *
     * @param  string  $name  Denomination name to query.
     *
     * @return array{approved: bool, clave: string|null}|null
     */
    private function queryMuaStatus(string $name): ?array
    {
        // TODO: Call the MUA authorized-names endpoint and parse the DataTables response.
        // When the name appears in `data` with an authorization_number → approved.
        // When not present after a known submission → rejected or still pending.
        //
        // For now we return null (unreachable) so the command is safe to schedule
        // without making unintended live requests.
        return null;
    }

    /**
     * Send a database notification to the assigned notario about the denomination result.
     *
     * @param  Registration  $registration  The registration linked to the denomination.
     * @param  string        $name          The denomination name.
     * @param  bool          $approved      True if approved, false if rejected.
     *
     * @return void
     */
    private function notifyNotario(Registration $registration, string $name, bool $approved): void
    {
        $notario = $registration->assignedNotario;

        if (! $notario) {
            return;
        }

        Notification::send($notario, new DenominationResolvedNotification($registration, $name, $approved));

        Log::info('Denomination resolution notification sent to notario.', [
            'registration_id' => $registration->id,
            'notario_id'      => $notario->id,
            'name'            => $name,
            'approved'        => $approved,
        ]);
    }
}
