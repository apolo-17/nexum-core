<?php

namespace App\Console\Commands;

use App\Enums\LegalNameEventTypeEnum;
use App\Enums\LegalNameStatusEnum;
use App\Models\LegalName;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rescues denominations left in a dead-end state by a bot-side failure.
 *
 * Two situations are recovered back to WAIT (ready for a manual resend from the
 * "Denominaciones (Pool)" dashboard):
 *   - REJECTED   — the bot reported a *technical* failure as `rejected` (a bug),
 *                  so a name that was never really judged got marked terminal.
 *   - SUBMITTING — the bot crashed mid-submit without sending any callback, so the
 *                  name is stuck "enviando" forever with no resolution.
 *
 * Dry-run by default: it only LISTS the affected rows. Pass --apply to perform the
 * reset. Scope can be narrowed with --id (one or more ULIDs) for surgical control.
 */
class RescueStuckDenominationsCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'mua:rescue-denominations
        {--apply : Actually perform the reset (otherwise dry-run only)}
        {--id=* : Limit to specific LegalName IDs (ULIDs)}
        {--days=2 : Only consider rows updated within the last N days (ignored when --id is used)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Return denominations stuck in REJECTED/SUBMITTING (bot failure) back to WAIT for a manual resend.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $candidates = $this->candidates();

        if ($candidates->isEmpty()) {
            $this->info('No hay denominaciones atoradas que rescatar con los criterios dados.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Nombre', 'Estado', 'Actualizada', 'Motivo de rechazo'],
            $candidates->map(fn (LegalName $name): array => [
                $name->id,
                $name->name,
                $name->status->value,
                $name->updated_at?->toDateTimeString(),
                $name->rejection_reason,
            ])->all(),
        );

        if (! $this->option('apply')) {
            $this->warn("DRY-RUN: {$candidates->count()} denominación(es) se regresarían a WAIT.");
            $this->line('Revisa la lista. Si es correcta, vuelve a correr con --apply (o acota con --id=...).');

            return self::SUCCESS;
        }

        $count = DB::transaction(function () use ($candidates): int {
            foreach ($candidates as $name) {
                $previousStatus = $name->status->value;

                $name->update([
                    'status' => LegalNameStatusEnum::WAIT->value,
                    'soldado_id' => null,
                    'submitted_at' => null,
                    'last_status_check_at' => null,
                    'rejection_reason' => null,
                    'portal_status' => null,
                ]);

                $name->recordEvent(
                    LegalNameEventTypeEnum::QUEUED,
                    'Regresada a la cola manualmente (rescate tras fallo técnico del bot).',
                    ['previous_status' => $previousStatus],
                    actorType: 'system',
                );
            }

            return $candidates->count();
        });

        $this->info("Listo: {$count} denominación(es) regresadas a WAIT. Reenvíalas desde el dashboard.");

        return self::SUCCESS;
    }

    /**
     * Resolve the set of denominations to rescue, honouring --id / --days.
     *
     * @return Collection<int, LegalName>
     */
    private function candidates(): Collection
    {
        $query = LegalName::query()->whereIn('status', [
            LegalNameStatusEnum::REJECTED->value,
            LegalNameStatusEnum::SUBMITTING->value,
        ]);

        $ids = $this->option('id');

        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        } else {
            $days = max(0, (int) $this->option('days'));
            $query->where('updated_at', '>=', Carbon::now()->subDays($days)->startOfDay());
        }

        return $query->orderBy('updated_at')->get();
    }
}
