<?php

namespace App\Filament\Widgets;

use App\Enums\AppointmentStatusEnum;
use App\Enums\LegalNameStatusEnum;
use App\Models\Soldado;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * KPI overview widget for the soldado role.
 *
 * Shows the logged-in soldado their own performance: companies they act in, pending
 * vs completed SAT appointments, and approved denominations. Only visible to soldados.
 */
class SoldadoStatsOverview extends StatsOverviewWidget
{
    /**
     * Sort before Filament built-in widgets (AccountWidget = -2).
     */
    protected static ?int $sort = -10;

    /**
     * Restrict this widget to soldado users only.
     */
    public static function canView(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        try {
            return $user->hasRole('soldado');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Build the stat cards scoped to the authenticated soldado.
     *
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        /** @var Soldado|null $soldado */
        $soldado = Auth::user()?->soldado;

        if ($soldado === null) {
            return [
                Stat::make('Mi perfil de soldado', 'No vinculado')
                    ->description('Contacta al administrador')
                    ->color('warning'),
            ];
        }

        $companies = $soldado->registrations()->count();

        $completedAppointments = $soldado->appointments()
            ->where('status', AppointmentStatusEnum::SCHEDULED->value)
            ->count();

        $pendingAppointments = $soldado->appointments()
            ->whereNotIn('status', [AppointmentStatusEnum::SCHEDULED->value])
            ->count();

        $approvedDenominations = $soldado->legalNames()
            ->where('status', LegalNameStatusEnum::APPROVED->value)
            ->count();

        return [
            Stat::make('Mis empresas', $companies)
                ->description('Actas donde participo')
                ->color('primary'),

            Stat::make('Citas completadas', $completedAppointments)
                ->description($pendingAppointments > 0 ? "{$pendingAppointments} pendiente(s)" : 'Sin pendientes')
                ->color($pendingAppointments > 0 ? 'warning' : 'success'),

            Stat::make('Denominaciones aprobadas', $approvedDenominations)
                ->description('Con mi FIEL')
                ->color('success'),
        ];
    }
}
