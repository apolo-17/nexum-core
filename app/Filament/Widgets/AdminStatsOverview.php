<?php

namespace App\Filament\Widgets;

use App\Enums\AppointmentStatusEnum;
use App\Enums\AppointmentTypeEnum;
use App\Enums\LegalNameStatusEnum;
use App\Models\Appointment;
use App\Models\LegalName;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * KPI overview for the super_admin role, focused on the SAT/denomination operation:
 * how many pool names are free, how many citas wait for a date, what is coming up
 * (split RFC vs e.firma), and how long it takes on average to land a cita.
 */
class AdminStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -10;

    public static function canView(): bool
    {
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        try {
            return $user->hasRole('super_admin') || $user->roles->isEmpty();
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        // 1. Denominaciones libres en el pool (aprobadas y sin empresa asignada).
        $poolFree = LegalName::query()
            ->whereNull('registration_id')
            ->where('status', LegalNameStatusEnum::APPROVED->value)
            ->count();

        // 2. Citas ya formadas que esperan que el SAT les asigne fecha.
        $pendingDate = Appointment::where('status', AppointmentStatusEnum::FORMED->value)->count();

        // 3. Próximas a asistir (agendadas, con fecha futura), separadas por tipo.
        $upcoming = fn (AppointmentTypeEnum $type): int => Appointment::query()
            ->where('status', AppointmentStatusEnum::SCHEDULED->value)
            ->where('type', $type->value)
            ->where('scheduled_at', '>=', now())
            ->count();

        $upcomingRfc = $upcoming(AppointmentTypeEnum::RFC);
        $upcomingFiel = $upcoming(AppointmentTypeEnum::FIEL);

        // 4. Tiempo promedio para conseguir cita: de formarla a la fecha asignada.
        $avgDays = Appointment::query()
            ->where('status', AppointmentStatusEnum::SCHEDULED->value)
            ->whereNotNull('formed_at')
            ->whereNotNull('scheduled_at')
            ->get(['formed_at', 'scheduled_at'])
            ->avg(fn (Appointment $a): float => $a->formed_at->diffInHours($a->scheduled_at) / 24);

        $avgLabel = $avgDays !== null ? number_format((float) $avgDays, 1).' días' : '—';

        return [
            Stat::make('Denominaciones libres', $poolFree)
                ->description('Disponibles en el pool')
                ->descriptionIcon('heroicon-m-tag')
                ->color($poolFree > 0 ? 'primary' : 'warning'),

            Stat::make('Citas sin fecha', $pendingDate)
                ->description('Formadas, esperando fecha del SAT')
                ->descriptionIcon('heroicon-m-clock')
                ->color($pendingDate > 0 ? 'warning' : 'gray'),

            Stat::make('Próximas · Registro RFC', $upcomingRfc)
                ->description('Con fecha, por asistir')
                ->descriptionIcon('heroicon-m-identification')
                ->color('info'),

            Stat::make('Próximas · e.firma (FIEL)', $upcomingFiel)
                ->description('Con fecha, por asistir')
                ->descriptionIcon('heroicon-m-key')
                ->color('info'),

            Stat::make('Tiempo prom. de cita', $avgLabel)
                ->description('De formar a la fecha asignada')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('success'),
        ];
    }
}
