<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Support\DashboardMetrics;
use Filament\Widgets\ChartWidget;

/**
 * Top courses by number of enrolments — "what is actually being taken" — so L&D
 * can see where learning is concentrated.
 */
class CourseCoverageChart extends ChartWidget
{
    protected static ?string $heading = 'Top courses by enrolment';

    protected static ?int $sort = 4;

    public static function canView(): bool
    {
        return auth()->user()?->can('reports.view') ?? false;
    }

    protected function getData(): array
    {
        $coverage = app(DashboardMetrics::class)->courseCoverage();

        return [
            'datasets' => [[
                'label' => 'Enrolments',
                'data' => $coverage->pluck('enrolments')->all(),
                'backgroundColor' => '#4f46e5',
            ]],
            'labels' => $coverage->pluck('title')->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
