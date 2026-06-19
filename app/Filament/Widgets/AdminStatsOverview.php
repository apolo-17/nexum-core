<?php

namespace App\Filament\Widgets;

use App\Enums\RegistrationStatusEnum;
use App\Models\Registration;
use App\Models\Task;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * KPI overview widget for the super_admin role.
 *
 * Shows system-wide metrics: total active, on-hold, completed, and cancelled
 * expedients, plus overdue tasks and upcoming e.firma appointments.
 * Only visible to users with the super_admin role.
 */
class AdminStatsOverview extends StatsOverviewWidget
{
    /**
     * Sort before Filament built-in widgets (AccountWidget = -2).
     *
     * @var int|null
     */
    protected static ?int $sort = -10;

    /**
     * Restrict this widget to super_admin users only.
     *
     * @return bool
     */
    public static function canView(): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    /**
     * Build the stat cards for the admin dashboard.
     *
     * Queries are intentionally simple counts — no N+1 risk,
     * each stat fires a single indexed query against status or stage.
     *
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $active    = Registration::where('status', RegistrationStatusEnum::ACTIVE)->count();
        $onHold    = Registration::where('status', RegistrationStatusEnum::ON_HOLD)->count();
        $completed = Registration::where('status', RegistrationStatusEnum::COMPLETED)->count();
        $cancelled = Registration::where('status', RegistrationStatusEnum::CANCELLED)->count();

        $overdueCount = Task::whereNull('completed_at')
            ->whereNotNull('due_date')
            ->where('due_date', '<', today())
            ->count();

        $efirmaUpcoming = Registration::where('status', RegistrationStatusEnum::ACTIVE)
            ->whereNotNull('efirma_appointment_at')
            ->whereBetween('efirma_appointment_at', [now()->startOfDay(), now()->addDays(7)->endOfDay()])
            ->count();

        return [
            Stat::make('Activos', $active)
                ->description('En proceso')
                ->color('primary'),

            Stat::make('En pausa', $onHold)
                ->description('Requieren seguimiento')
                ->color($onHold > 0 ? 'warning' : 'gray'),

            Stat::make('Completados', $completed)
                ->description('Empresa operativa')
                ->color('success'),

            Stat::make('Cancelados', $cancelled)
                ->description('Sin continuar')
                ->color($cancelled > 0 ? 'danger' : 'gray'),

            Stat::make('Tareas vencidas', $overdueCount)
                ->description('Acción inmediata requerida')
                ->color($overdueCount > 0 ? 'danger' : 'success'),

            Stat::make('Citas e.firma (7 días)', $efirmaUpcoming)
                ->description('Próximas')
                ->color('info'),
        ];
    }
}
