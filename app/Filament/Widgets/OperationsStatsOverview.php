<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\AppointmentStatusEnum;
use App\Enums\LegalNameStatusEnum;
use App\Models\Appointment;
use App\Models\LegalName;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * Quick-glance counts for the super_admin dashboard: denominations and SAT appointments.
 *
 * Sits above the two board widgets and gives the 3-second picture — how many
 * denominations are in flight, how many appointments are waiting at each stage, and how
 * many scheduled appointments are still missing paperwork.
 */
class OperationsStatsOverview extends StatsOverviewWidget
{
    /**
     * Sort right below the existing AdminStatsOverview (-10).
     */
    protected static ?int $sort = -9;

    /**
     * Only the super_admin sees this operations panel.
     */
    public static function canView(): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    /**
     * Build the stat cards.
     *
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $denominationsInFlight = LegalName::whereIn('status', [
            LegalNameStatusEnum::DRAFT->value,
            LegalNameStatusEnum::WAIT->value,
            LegalNameStatusEnum::SUBMITTING->value,
            LegalNameStatusEnum::PENDING->value,
            LegalNameStatusEnum::PROCESS->value,
        ])->count();

        $pendingForming = Appointment::where('status', AppointmentStatusEnum::PENDING_FORMING)->count();
        $formed = Appointment::where('status', AppointmentStatusEnum::FORMED)->count();
        $scheduled = Appointment::where('status', AppointmentStatusEnum::SCHEDULED)->count();

        // Scheduled appointments still missing the acuse (the tax-address proof lives on
        // the registration, so acuse-missing is the cheap, indexed signal here).
        $missingAcuse = Appointment::where('status', AppointmentStatusEnum::SCHEDULED)
            ->whereNull('acknowledgment_path')
            ->count();

        return [
            Stat::make('Denominaciones en proceso', $denominationsInFlight)
                ->description('Borrador, en cola o en la SE')
                ->color($denominationsInFlight > 0 ? 'info' : 'gray'),

            Stat::make('Citas por formar', $pendingForming)
                ->description('Aún no están en la fila virtual')
                ->color($pendingForming > 0 ? 'warning' : 'gray'),

            Stat::make('Citas formadas', $formed)
                ->description('En la fila, esperando fecha del SAT')
                ->color($formed > 0 ? 'warning' : 'gray'),

            Stat::make('Citas agendadas', $scheduled)
                ->description('Con fecha y hora asignada')
                ->color('success'),

            Stat::make('Acuse pendiente', $missingAcuse)
                ->description('Citas agendadas sin acuse')
                ->color($missingAcuse > 0 ? 'danger' : 'success'),
        ];
    }
}
