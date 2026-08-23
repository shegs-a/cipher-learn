<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Support\DashboardMetrics;
use Filament\Widgets\ChartWidget;

/**
 * Where learners are in their assigned training, as a doughnut — assigned vs in
 * progress vs completed vs failed. Shows at a glance whether training is moving.
 */
class EnrolmentsByStatusChart extends ChartWidget
{
    protected static ?string $heading = 'Enrolments by status';

    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return auth()->user()?->can('reports.view') ?? false;
    }

    protected function getData(): array
    {
        $data = app(DashboardMetrics::class)->enrolmentsByStatus();

        return [
            'datasets' => [[
                'data' => array_values($data),
                // Assigned (slate), In progress (brand), Completed (green), Failed (red).
                'backgroundColor' => ['#94a3b8', '#4f46e5', '#22c55e', '#ef4444'],
            ]],
            'labels' => array_keys($data),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
