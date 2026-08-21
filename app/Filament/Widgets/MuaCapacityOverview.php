<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\LegalNameStatusEnum;
use App\Models\LegalName;
use App\Models\Soldado;
use App\Services\Mua\MuaSubmissionService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * How much MUA capacity there is right now, on the denominations screen.
 *
 * The SE allows ONE in-process denomination per account (RFC), so the number of
 * free soldiers is the hard ceiling on how many denominations can be sent at this
 * moment. Before this, that number only appeared inside the bulk-send confirmation
 * modal, so the answer to "is everyone already working, or did we simply never
 * configure more soldiers?" required clicking a button that sends things.
 *
 * It also surfaces soldiers flagged for MUA whose FIEL is incomplete: they are
 * silently skipped when picking an account, so without this they look available
 * and are not.
 */
class MuaCapacityOverview extends StatsOverviewWidget
{
    /**
     * Show above the denominations table.
     */
    protected static ?int $sort = -20;

    /**
     * Build the capacity stats: free soldiers, occupied ones, and queue depth.
     *
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $availability = app(MuaSubmissionService::class)->fielAvailability();

        // Flagged for MUA but missing certificate, key or password: never selected,
        // and invisible unless we say so here.
        $incomplete = Soldado::where('available_for_mua', true)
            ->where('is_active', true)
            ->get()
            ->reject(fn (Soldado $soldado): bool => $soldado->isReadyForMua())
            ->count();

        $queued = LegalName::whereNull('registration_id')
            ->whereIn('status', [
                LegalNameStatusEnum::DRAFT->value,
                LegalNameStatusEnum::WAIT->value,
            ])
            ->count();

        $free = $availability['free'];

        return [
            Stat::make('Soldados libres', $free)
                ->description($free > 0
                    ? implode(' · ', $availability['free_names'])
                    : 'Ninguno puede tomar una denominación ahora')
                ->descriptionIcon($free > 0 ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
                ->color($free > 0 ? 'success' : 'danger'),

            Stat::make('Soldados ocupados', $availability['busy'])
                ->description('Con una denominación en la SE (1 por RFC)')
                ->descriptionIcon('heroicon-m-clock')
                ->color($availability['busy'] > 0 ? 'warning' : 'gray'),

            Stat::make('FIEL lista para MUA', $availability['ready'])
                ->description($incomplete > 0
                    ? "{$incomplete} soldado(s) sin FIEL completa"
                    : 'Todas las FIEL configuradas están completas')
                ->descriptionIcon($incomplete > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-identification')
                ->color($incomplete > 0 ? 'warning' : 'primary'),

            Stat::make('Pendientes de envío', $queued)
                ->description($this->queueDescription($queued, $free))
                ->descriptionIcon('heroicon-m-paper-airplane')
                ->color($queued > 0 && $free === 0 ? 'warning' : 'gray'),
        ];
    }

    /**
     * Describe what would happen to the queue if the operator sent right now.
     *
     * @param  int  $queued  Denominations waiting to be sent.
     * @param  int  $free  Soldiers able to take one.
     */
    private function queueDescription(int $queued, int $free): string
    {
        if ($queued === 0) {
            return 'Nada en cola';
        }

        if ($free === 0) {
            return 'Esperan a que se libere un soldado';
        }

        return $queued > $free
            ? "Saldrían {$free} ahora; ".($queued - $free).' esperan turno'
            : 'Hay capacidad para enviarlas todas';
    }
}
