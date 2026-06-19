<?php

namespace App\Filament\Widgets;

use App\Enums\RegistrationStageEnum;
use App\Enums\RegistrationStatusEnum;
use App\Models\Registration;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Auth;

/**
 * Bar chart showing active registrations distributed across the 8 pipeline stages.
 *
 * Excludes cancelled registrations to give the admin a clean view of
 * live workload per stage. Respects the defined stage order from orderedStages().
 * Only visible to super_admin users.
 */
class StageDistributionChart extends ChartWidget
{
    /**
     * Widget heading shown above the chart.
     *
     * @var string|null
     */
    protected ?string $heading = 'Distribución por etapa';

    /**
     * Description shown below the heading.
     *
     * @var string|null
     */
    protected ?string $description = 'Expedientes activos y en pausa por etapa (excluye cancelados)';

    /**
     * Sort immediately after AdminStatsOverview (sort = -10).
     *
     * @var int|null
     */
    protected static ?int $sort = -9;

    /**
     * Full-width layout so all 8 stage labels are readable.
     *
     * @var int|string|array<string, mixed>
     */
    protected int | string | array $columnSpan = 'full';

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
     * Build the dataset for the stage distribution bar chart.
     *
     * Groups non-cancelled registrations by stage value and maps counts
     * to the ordered stage labels defined in RegistrationStageEnum::orderedStages().
     *
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $countsRaw = Registration::whereNot('status', RegistrationStatusEnum::CANCELLED)
            ->selectRaw('stage, COUNT(*) as total')
            ->groupBy('stage')
            ->pluck('total', 'stage')
            ->toArray();

        $labels = [];
        $data   = [];

        foreach (RegistrationStageEnum::orderedStages() as $stage) {
            $labels[] = $stage->label();
            $data[]   = $countsRaw[$stage->value] ?? 0;
        }

        return [
            'datasets' => [
                [
                    'label'           => 'Expedientes',
                    'data'            => $data,
                    'backgroundColor' => 'rgba(59, 130, 246, 0.85)',
                    'borderColor'     => 'rgba(37, 99, 235, 1)',
                    'borderWidth'     => 1,
                    'borderRadius'    => 4,
                ],
            ],
            'labels' => $labels,
        ];
    }

    /**
     * Return Chart.js options to improve readability of the 8-stage bar chart.
     *
     * Hides the legend (single dataset, label is self-explanatory),
     * and rotates x-axis tick labels to prevent overlap on narrower screens.
     *
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],
            'scales' => [
                'x' => [
                    'ticks' => [
                        'maxRotation' => 30,
                        'minRotation' => 0,
                    ],
                ],
                'y' => [
                    'beginAtZero' => true,
                    'ticks'       => [
                        'stepSize' => 1,
                    ],
                ],
            ],
        ];
    }

    /**
     * Return the chart type identifier for Chart.js.
     *
     * @return string
     */
    protected function getType(): string
    {
        return 'bar';
    }
}
