<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Support\DashboardMetrics;
use Filament\Widgets\ChartWidget;

/**
 * Completions per week over the last two months — the "is training actually
 * happening" trend, from `enrollments.completed_at`.
 */
class CompletionsTrendChart extends ChartWidget
{
    protected static ?string $heading = 'Completions (last 8 weeks)';

    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return auth()->user()?->can('reports.view') ?? false;
    }

    protected function getData(): array
    {
        $data = app(DashboardMetrics::class)->completionsByWeek(8);

        return [
            'datasets' => [[
                'label' => 'Completions',
                'data' => array_values($data),
                'borderColor' => '#4f46e5',
                'backgroundColor' => 'rgba(41, 109, 179, 0.1)',
                'fill' => true,
                'tension' => 0.3,
            ]],
            'labels' => array_keys($data),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
