<?php

namespace App\Filament\Widgets;

use App\Enums\RegistrationStatusEnum;
use App\Models\Registration;
use App\Models\Task;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * KPI overview widget for the notario role.
 *
 * Shows metrics scoped to the authenticated notario's own portfolio:
 * their assigned active expedients, pending tasks (with overdue alert),
 * upcoming e.firma appointments, and completions for the current month.
 * Only visible to users with the notario role.
 */
class NotarioStatsOverview extends StatsOverviewWidget
{
    /**
     * Sort before Filament built-in widgets (AccountWidget = -2).
     */
    protected static ?int $sort = -10;

    /**
     * Restrict this widget to notario users only.
     */
    public static function canView(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        try {
            return $user->hasRole('notario');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Build the stat cards scoped to the authenticated notario.
     *
     * All queries filter by the current user's ID via assigned_notario_id
     * or assigned_to, ensuring no cross-notario data leaks in the widget.
     *
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $userId = Auth::id();

        $activeAssigned = Registration::where('assigned_notario_id', $userId)
            ->where('status', RegistrationStatusEnum::ACTIVE)
            ->count();

        $pendingTasks = Task::where('assigned_to', $userId)
            ->whereNull('completed_at')
            ->count();

        $overdueTasks = Task::where('assigned_to', $userId)
            ->whereNull('completed_at')
            ->whereNotNull('due_date')
            ->where('due_date', '<', today())
            ->count();

        $efirmaUpcoming = Registration::where('assigned_notario_id', $userId)
            ->where('status', RegistrationStatusEnum::ACTIVE)
            ->whereNotNull('efirma_appointment_at')
            ->whereBetween('efirma_appointment_at', [now()->startOfDay(), now()->addDays(7)->endOfDay()])
            ->count();

        $completedThisMonth = Registration::where('assigned_notario_id', $userId)
            ->where('status', RegistrationStatusEnum::COMPLETED)
            ->whereMonth('completed_at', now()->month)
            ->whereYear('completed_at', now()->year)
            ->count();

        return [
            Stat::make('Mis expedientes activos', $activeAssigned)
                ->description('Asignados a mí')
                ->color('primary'),

            Stat::make('Mis tareas pendientes', $pendingTasks)
                ->description($overdueTasks > 0 ? "{$overdueTasks} vencida(s)" : 'Al día')
                ->color($overdueTasks > 0 ? 'warning' : 'success'),

            Stat::make('Citas e.firma (7 días)', $efirmaUpcoming)
                ->description('Próximas')
                ->color('info'),

            Stat::make('Completados este mes', $completedThisMonth)
                ->description(now()->translatedFormat('F Y'))
                ->color('success'),
        ];
    }
}
