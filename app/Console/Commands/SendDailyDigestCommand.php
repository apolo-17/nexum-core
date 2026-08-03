<?php

namespace App\Console\Commands;

use App\Enums\NotificationEventEnum;
use App\Notifications\DailyDigestReport;
use App\Services\Notifications\EventNotifier;
use App\Services\Reporting\DailyDigestNarrator;
use App\Services\Reporting\DailyDigestService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;

/**
 * Builds and sends the daily 8:00 expedient digest.
 *
 * Scheduled on weekdays in bootstrap/app.php, and safe to run by hand at any time
 * (a second run just sends a second copy — nothing is written to the database).
 * The digest is sent even when nothing is overdue: a quiet report is the signal
 * that the pipeline is being watched.
 */
class SendDailyDigestCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'expedientes:daily-digest
        {--dry-run : Print the digest in the console instead of emailing it}
        {--no-ai : Skip the Claude briefing and use the static introduction}';

    /**
     * @var string
     */
    protected $description = 'Envía el reporte diario con el estado de los expedientes activos';

    /**
     * Build the digest, have Claude write the briefing, and hand it to EventNotifier.
     *
     * @param  DailyDigestService  $digestService  Builds the figures from the database.
     * @param  DailyDigestNarrator  $narrator  Writes the briefing over those figures.
     * @param  EventNotifier  $notifier  Resolves recipients and delivers the email.
     * @return int Console exit code.
     */
    public function handle(
        DailyDigestService $digestService,
        DailyDigestNarrator $narrator,
        EventNotifier $notifier,
    ): int {
        $dryRun = (bool) $this->option('dry-run');

        // Building the report and calling Claude is wasted work when the event is
        // switched off or nobody is subscribed to it.
        if (! $dryRun && ! $notifier->hasRecipients(NotificationEventEnum::DAILY_DIGEST)) {
            $this->warn('El evento "Reporte diario de expedientes" está desactivado o sin destinatarios. No se envió nada.');

            return CommandAlias::SUCCESS;
        }

        $digest = $digestService->build(CarbonImmutable::now());

        $briefing = $this->option('no-ai') ? null : $narrator->narrate($digest);

        if ($briefing === null && ! $this->option('no-ai')) {
            $this->warn('El resumen de Claude no estuvo disponible; se envía el reporte con la introducción estática.');
        }

        if ($dryRun) {
            $this->render($digest, $briefing);

            return CommandAlias::SUCCESS;
        }

        $notifier->notify(
            NotificationEventEnum::DAILY_DIGEST,
            new DailyDigestReport($digest, $briefing),
        );

        $this->info(sprintf(
            'Reporte diario encolado: %d activos, %d atrasados.',
            $digest['totals']['active'],
            $digest['totals']['overdue'],
        ));

        return CommandAlias::SUCCESS;
    }

    /**
     * Print the digest to the console so it can be inspected without sending mail.
     *
     * @param  array<string, mixed>  $digest  Digest payload.
     * @param  array{greeting: string, summary: string, priorities: array<int, string>}|null  $briefing
     *                                                                                                   Claude's briefing, when available.
     */
    private function render(array $digest, ?array $briefing): void
    {
        if ($briefing !== null) {
            $this->newLine();
            $this->line($briefing['greeting']);
            $this->newLine();
            $this->line($briefing['summary']);

            foreach ($briefing['priorities'] as $index => $priority) {
                $this->line(($index + 1).'. '.$priority);
            }
        }

        $this->newLine();
        $this->table(
            ['Indicador', 'Total'],
            collect($digest['totals'])->map(fn (int $v, string $k): array => [$k, $v])->values()->all(),
        );

        if ($digest['alerts']['items'] !== []) {
            $this->table(
                ['Estado', 'Código', 'Empresa', 'Motivo', 'Días'],
                array_map(fn (array $a): array => [
                    $a['severity'],
                    $a['code'],
                    $a['company'],
                    $a['reason'],
                    $a['days'],
                ], $digest['alerts']['items']),
            );
        }

        if ($digest['oldest'] !== []) {
            $this->table(
                ['Código', 'Empresa', 'Etapa', 'En etapa', 'Total'],
                array_map(fn (array $r): array => [
                    $r['code'],
                    $r['company'],
                    $r['stage']->label(),
                    $r['days_in_stage'].' d',
                    $r['days_total'].' d',
                ], $digest['oldest']),
            );
        }
    }
}
