<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Hris\Sync\LeaveDataStatus;
use App\Support\DashboardMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The headline figures on the admin overview: people, training in play, and how
 * much of it is landing. Thin over {@see DashboardMetrics} — all the arithmetic
 * (and its tenant-scoping) lives there and is tested directly.
 *
 * Gated by `reports.view` (held by Manager / L&D / Tenant Admin, not a Content
 * Administrator), so an authoring-only operator sees no org metrics.
 */
class AdminOverviewStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return auth()->user()?->can('reports.view') ?? false;
    }

    protected function getStats(): array
    {
        $m = app(DashboardMetrics::class);

        $overdue = $m->overdue();
        $recert = $m->recertDueSoon();

        $stats = [
            Stat::make('Active headcount', $m->activeHeadcount())
                ->description($m->leavers().' leavers')
                ->color('primary'),

            Stat::make('Active enrolments', $m->activeEnrolments())
                ->description($overdue.' overdue')
                ->color($overdue > 0 ? 'danger' : 'gray'),

            Stat::make('Completion rate', $m->completionRate().'%')
                ->description('of assigned training')
                ->color('success'),

            Stat::make('Pass rate', $m->passRate().'%')
                ->description('of graded assessments')
                ->color('success'),

            Stat::make('Certificates', $m->certificatesIssued())
                ->description($recert.' due for recert ≤ 60 days')
                ->color($recert > 0 ? 'warning' : 'gray'),
        ];

        $leave = $this->leaveDataStat();

        return $leave === null ? $stats : [...$stats, $leave];
    }

    /**
     * How fresh the leave data behind the assignment leave-check is — only shown for
     * organisations whose HR system provides leave. Amber when stale, so an admin
     * notices a sync that has quietly stopped working before it matters.
     */
    private function leaveDataStat(): ?Stat
    {
        $tenant = auth()->user()?->tenant;
        $status = app(LeaveDataStatus::class);

        if ($tenant === null || ! $status->isTracked($tenant)) {
            return null;
        }

        $last = $status->lastSuccessfulSync($tenant);
        $stale = $status->isStale($tenant);

        return Stat::make('Leave data', $last === null ? 'Never synced' : $last->diffForHumans())
            ->description($stale ? 'Out of date — assignments may miss recent leave' : 'Used to check leave when assigning')
            ->color($stale ? 'warning' : 'success');
    }
}
