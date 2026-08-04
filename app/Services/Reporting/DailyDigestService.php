<?php

namespace App\Services\Reporting;

use App\Enums\AppointmentStatusEnum;
use App\Enums\DocumentTypeEnum;
use App\Enums\LegalNameStatusEnum;
use App\Enums\RegistrationStageEnum;
use App\Enums\RegistrationStatusEnum;
use App\Filament\Resources\RegistrationResource;
use App\Models\Registration;
use App\Models\StageTransition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the payload for the daily 8:00 expedient digest.
 *
 * Every number in the report is computed here, in PHP, from data that already
 * exists (stage_transitions, tasks, appointments, legal_names, documents). The
 * AI narrator only ever writes prose over this payload — it never computes or
 * invents a figure. See DailyDigestNarrator.
 */
class DailyDigestService
{
    /**
     * Maximum number of attention items listed in the email body. Anything beyond
     * this is reported as an explicit overflow count so a long tail is never
     * silently hidden.
     */
    private const MAX_ALERTS = 8;

    /**
     * Number of expedients shown in the "oldest in their stage" table.
     */
    private const MAX_OLDEST = 5;

    /**
     * Days a SAT appointment may sit formed (queued, no date assigned) before it
     * is surfaced as needing a follow-up.
     */
    private const APPOINTMENT_STALE_DAYS = 10;

    /**
     * Build the complete digest payload for a given cut-off moment.
     *
     * @param  CarbonImmutable|null  $asOf  Cut-off timestamp; defaults to now.
     * @return array<string, mixed> Digest payload consumed by the mail view and the narrator.
     */
    public function build(?CarbonImmutable $asOf = null): array
    {
        $asOf = $asOf ?? CarbonImmutable::now();

        $registrations = $this->activeRegistrations();
        $rows = $registrations->map(fn (Registration $r): array => $this->summarise($r, $asOf));

        return [
            'as_of' => $asOf,
            'since' => $this->previousBusinessDay($asOf),
            'totals' => $this->totals($rows, $asOf),
            'alerts' => $this->alerts($rows),
            'oldest' => $this->oldest($rows),
            'distribution' => $this->distribution($rows),
            'movements' => $this->movements($asOf),
        ];
    }

    // -------------------------------------------------------------------------
    // Private — data collection
    // -------------------------------------------------------------------------

    /**
     * Load every registration that still counts as in-flight, with the relations
     * the digest needs eager-loaded so the whole report stays a handful of queries.
     *
     * ON_HOLD is included so it can be reported separately, but it is never
     * flagged as overdue — the team paused it on purpose.
     *
     * @return Collection<int, Registration>
     */
    private function activeRegistrations(): Collection
    {
        return Registration::query()
            ->whereIn('status', [
                RegistrationStatusEnum::ACTIVE->value,
                RegistrationStatusEnum::ON_HOLD->value,
            ])
            ->where('stage', '!=', RegistrationStageEnum::COMPLETED->value)
            ->with([
                'primaryLegalName',
                'legalNames',
                'notario',
                'shareholders',
                'documents',
                'appointments',
                'stageTransitions',
                'tasks' => fn ($q) => $q->whereNull('completed_at'),
            ])
            ->get();
    }

    /**
     * Reduce one registration to the flat row the report works with.
     *
     * @param  Registration  $registration  The expedient being summarised.
     * @param  CarbonImmutable  $asOf  Cut-off timestamp for every age calculation.
     * @return array<string, mixed> Flat row: identity, ages, severity and issue list.
     */
    private function summarise(Registration $registration, CarbonImmutable $asOf): array
    {
        $stage = $registration->stage;
        $enteredStageAt = $this->enteredStageAt($registration);

        $daysInStage = (int) $enteredStageAt->diffInDays($asOf);
        $daysTotal = (int) CarbonImmutable::parse($registration->created_at)->diffInDays($asOf);

        $onHold = $registration->status === RegistrationStatusEnum::ON_HOLD;

        // Paused expedients keep their counters visible but never raise an SLA flag.
        $severity = match (true) {
            $onHold => 'paused',
            $daysInStage >= $stage->slaOverdueDays() => 'overdue',
            $daysInStage >= $stage->slaWarningDays() => 'warning',
            default => 'ok',
        };

        return [
            'code' => $registration->singapur_client_code ?? '—',
            'company' => $registration->primaryLegalName?->name ?? 'Sin denominación',
            'stage' => $stage,
            'owner' => $registration->notario?->name,
            'days_in_stage' => $daysInStage,
            'days_total' => $daysTotal,
            'severity' => $severity,
            'on_hold' => $onHold,
            'url' => RegistrationResource::getUrl('view', ['record' => $registration], panel: 'admin'),
            'issues' => $onHold ? [] : $this->issues($registration, $asOf, $daysInStage),
        ];
    }

    /**
     * Resolve when the registration entered its current stage.
     *
     * Falls back to the creation date for expedients that never transitioned
     * (everything that just arrived from the relay sits in DATA_RECEIVED).
     *
     * @param  Registration  $registration  The expedient to inspect.
     * @return CarbonImmutable Moment the current stage began.
     */
    private function enteredStageAt(Registration $registration): CarbonImmutable
    {
        $lastTransition = $registration->stageTransitions
            ->where('to_stage', $registration->stage)
            ->last();

        return CarbonImmutable::parse(
            $lastTransition?->created_at ?? $registration->created_at
        );
    }

    /**
     * Collect the human-readable reasons this expedient needs attention today.
     *
     * Each issue carries its own severity so a blocked denomination can outrank a
     * merely slow stage. Returns an empty array for a healthy expedient.
     *
     * @param  Registration  $registration  The expedient to inspect.
     * @param  CarbonImmutable  $asOf  Cut-off timestamp.
     * @param  int  $daysInStage  Days already spent in the current stage.
     * @return array<int, array{severity: string, reason: string, days: int}>
     */
    private function issues(Registration $registration, CarbonImmutable $asOf, int $daysInStage): array
    {
        $issues = [];
        $stage = $registration->stage;

        // 1. The stage itself has run past its threshold.
        if ($daysInStage >= $stage->slaWarningDays()) {
            $issues[] = [
                'severity' => $daysInStage >= $stage->slaOverdueDays() ? 'overdue' : 'warning',
                'reason' => "{$stage->label()} — sin avanzar desde hace {$daysInStage} días",
                'days' => $daysInStage,
            ];
        }

        // 2. A denomination came back rejected and no other name was authorised.
        $hasApproved = $registration->legalNames
            ->contains(fn ($n): bool => $n->status === LegalNameStatusEnum::APPROVED);

        $rejected = $registration->legalNames
            ->filter(fn ($n): bool => $n->status === LegalNameStatusEnum::REJECTED);

        if (! $hasApproved && $rejected->isNotEmpty()) {
            $issues[] = [
                'severity' => 'warning',
                'reason' => 'Denominación rechazada por la SE — hace falta proponer otro nombre',
                'days' => (int) CarbonImmutable::parse($rejected->last()->updated_at)->diffInDays($asOf),
            ];
        }

        // 3. A SAT appointment is queued in the virtual line with no date assigned.
        foreach ($registration->appointments as $appointment) {
            if ($appointment->status !== AppointmentStatusEnum::FORMED || $appointment->scheduled_at !== null) {
                continue;
            }

            $waiting = (int) CarbonImmutable::parse($appointment->formed_at ?? $appointment->created_at)->diffInDays($asOf);

            if ($waiting >= self::APPOINTMENT_STALE_DAYS) {
                $issues[] = [
                    'severity' => 'warning',
                    'reason' => "Cita {$appointment->type->label()} formada sin fecha — el SAT no ha asignado horario",
                    'days' => $waiting,
                ];
            }
        }

        // 4. KYC package still incomplete while the expedient is meant to be validating identity.
        if (in_array($stage, [RegistrationStageEnum::DATA_RECEIVED, RegistrationStageEnum::IDENTITY_VALIDATION], true)) {
            $missing = $registration->missingKycDocuments();

            if ($missing !== []) {
                $issues[] = [
                    'severity' => 'warning',
                    'reason' => 'Faltan documentos KYC: '.$this->describeMissingDocuments($missing),
                    'days' => $daysInStage,
                ];
            }
        }

        // 5. Tasks past their due date.
        $overdueTasks = $registration->tasks
            ->filter(fn ($t): bool => $t->due_date !== null && $t->due_date->lt($asOf->startOfDay()));

        if ($overdueTasks->isNotEmpty()) {
            $issues[] = [
                'severity' => 'warning',
                'reason' => $overdueTasks->count() === 1
                    ? "Tarea vencida: {$overdueTasks->first()->title}"
                    : "{$overdueTasks->count()} tareas vencidas",
                'days' => (int) $overdueTasks->min('due_date')->diffInDays($asOf),
            ];
        }

        return $issues;
    }

    /**
     * Turn the missing-KYC map into a readable Spanish enumeration.
     *
     * @param  array<string, int>  $missing  Map of DocumentTypeEnum value → missing count.
     * @return string Comma-separated document labels.
     */
    private function describeMissingDocuments(array $missing): string
    {
        $labels = [];

        foreach ($missing as $type => $count) {
            $label = DocumentTypeEnum::tryFrom($type)?->label() ?? $type;
            $labels[] = $count > 1 ? "{$label} (x{$count})" : $label;
        }

        return implode(', ', $labels);
    }

    // -------------------------------------------------------------------------
    // Private — report sections
    // -------------------------------------------------------------------------

    /**
     * Headline counters shown at the top of the email and in the subject line.
     *
     * @param  Collection<int, array<string, mixed>>  $rows  Summarised registrations.
     * @param  CarbonImmutable  $asOf  Cut-off timestamp.
     * @return array<string, int> Counter map.
     */
    private function totals(Collection $rows, CarbonImmutable $asOf): array
    {
        $since = $this->previousBusinessDay($asOf);

        return [
            'active' => $rows->where('on_hold', false)->count(),
            'overdue' => $rows->where('severity', 'overdue')->count(),
            'warning' => $rows->where('severity', 'warning')->count(),
            'on_hold' => $rows->where('on_hold', true)->count(),
            'advanced' => StageTransition::query()
                ->where('created_at', '>=', $since)
                ->where('created_at', '<', $asOf)
                ->distinct('registration_id')
                ->count('registration_id'),
            'new' => Registration::query()
                ->where('created_at', '>=', $since)
                ->where('created_at', '<', $asOf)
                ->count(),
            'completed' => Registration::query()
                ->whereNotNull('completed_at')
                ->where('completed_at', '>=', $since)
                ->where('completed_at', '<', $asOf)
                ->count(),
        ];
    }

    /**
     * Flatten every issue into a single attention list, worst first.
     *
     * Capped at MAX_ALERTS with an explicit overflow count so the reader always
     * knows the list was trimmed.
     *
     * @param  Collection<int, array<string, mixed>>  $rows  Summarised registrations.
     * @return array{items: array<int, array<string, mixed>>, overflow: int}
     */
    private function alerts(Collection $rows): array
    {
        $items = [];

        foreach ($rows as $row) {
            foreach ($row['issues'] as $issue) {
                $items[] = [
                    'code' => $row['code'],
                    'company' => $row['company'],
                    'url' => $row['url'],
                    'severity' => $issue['severity'],
                    'reason' => $issue['reason'],
                    'days' => $issue['days'],
                ];
            }
        }

        usort($items, function (array $a, array $b): int {
            $rank = fn (string $s): int => $s === 'overdue' ? 0 : 1;

            return [$rank($a['severity']), -$a['days']] <=> [$rank($b['severity']), -$b['days']];
        });

        return [
            'items' => array_slice($items, 0, self::MAX_ALERTS),
            'overflow' => max(0, count($items) - self::MAX_ALERTS),
        ];
    }

    /**
     * The expedients that have spent the longest in their current stage.
     *
     * @param  Collection<int, array<string, mixed>>  $rows  Summarised registrations.
     * @return array<int, array<string, mixed>> Rows ordered by days in stage, descending.
     */
    private function oldest(Collection $rows): array
    {
        return $rows
            ->where('on_hold', false)
            ->sortByDesc('days_in_stage')
            ->take(self::MAX_OLDEST)
            ->values()
            ->all();
    }

    /**
     * Count and average age per stage, so the team can see where the portfolio sits.
     *
     * Stages with no active expedients are omitted rather than shown as zeros.
     *
     * @param  Collection<int, array<string, mixed>>  $rows  Summarised registrations.
     * @return array<int, array<string, mixed>> One entry per occupied stage, in pipeline order.
     */
    private function distribution(Collection $rows): array
    {
        $active = $rows->where('on_hold', false);
        $peak = max(1, $active->countBy(fn (array $r): string => $r['stage']->value)->max() ?? 1);

        $distribution = [];

        foreach (RegistrationStageEnum::orderedStages() as $stage) {
            if ($stage === RegistrationStageEnum::COMPLETED) {
                continue;
            }

            $inStage = $active->where('stage', $stage);

            if ($inStage->isEmpty()) {
                continue;
            }

            $avgDays = (int) round($inStage->avg('days_in_stage'));

            $distribution[] = [
                'label' => $stage->shortLabel(),
                'count' => $inStage->count(),
                'avg_days' => $avgDays,
                'over_threshold' => $avgDays >= $stage->slaWarningDays(),
                'width' => (int) round($inStage->count() / $peak * 100),
            ];
        }

        return $distribution;
    }

    /**
     * Stage advances recorded since the previous business day.
     *
     * @param  CarbonImmutable  $asOf  Cut-off timestamp.
     * @return array<int, array{code: string, company: string, to: string}>
     */
    private function movements(CarbonImmutable $asOf): array
    {
        return StageTransition::query()
            ->with('registration.primaryLegalName')
            ->where('created_at', '>=', $this->previousBusinessDay($asOf))
            ->where('created_at', '<', $asOf)
            ->orderBy('created_at')
            ->get()
            ->map(fn (StageTransition $t): array => [
                'code' => $t->registration?->singapur_client_code ?? '—',
                'company' => $t->registration?->primaryLegalName?->name ?? 'Sin denominación',
                'to' => $t->to_stage->label(),
            ])
            ->all();
    }

    /**
     * Start of the window the "what moved" section covers.
     *
     * The digest only runs on weekdays, so Monday's edition must reach back over
     * the weekend to Friday morning rather than reporting an empty Sunday.
     *
     * @param  CarbonImmutable  $asOf  Cut-off timestamp.
     * @return CarbonImmutable Start of the reporting window.
     */
    private function previousBusinessDay(CarbonImmutable $asOf): CarbonImmutable
    {
        $previous = $asOf->subDay();

        while ($previous->isWeekend()) {
            $previous = $previous->subDay();
        }

        return $previous;
    }
}
