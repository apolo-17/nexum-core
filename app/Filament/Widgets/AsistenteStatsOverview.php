<?php

namespace App\Filament\Widgets;

use App\Enums\RegistrationStatusEnum;
use App\Models\Document;
use App\Models\Registration;
use App\Models\Task;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * KPI overview widget for the asistente_notario role.
 *
 * Shows operational metrics scoped to the authenticated asistente:
 * active expedients under their responsibility, pending tasks (with overdue alert),
 * and documents awaiting verification on their assigned registrations.
 * Only visible to users with the asistente_notario role.
 */
class AsistenteStatsOverview extends StatsOverviewWidget
{
    /**
     * Sort before Filament built-in widgets (AccountWidget = -2).
     */
    protected static ?int $sort = -10;

    /**
     * Restrict this widget to asistente_notario users only.
     */
    public static function canView(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        try {
            return $user->hasRole('asistente_notario');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Build the stat cards scoped to the authenticated asistente.
     *
     * Documents pending verification are scoped to registrations
     * assigned to this asistente to avoid showing unrelated work.
     *
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $userId = Auth::id();

        $activeAssigned = Registration::where('assigned_asistente_id', $userId)
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

        $pendingVerification = Document::whereHas('registration', function ($query) use ($userId) {
            $query->where('assigned_asistente_id', $userId);
        })->whereNull('verified_at')->count();

        return [
            Stat::make('Expedientes a mi cargo', $activeAssigned)
                ->description('Asignados a mí')
                ->color('primary'),

            Stat::make('Mis tareas pendientes', $pendingTasks)
                ->description($overdueTasks > 0 ? "{$overdueTasks} vencida(s)" : 'Al día')
                ->color($overdueTasks > 0 ? 'danger' : 'success'),

            Stat::make('Documentos por verificar', $pendingVerification)
                ->description('Sin verificación')
                ->color($pendingVerification > 0 ? 'warning' : 'success'),
        ];
    }
}
