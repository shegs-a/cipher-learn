<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\EmployeeStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\QuizAttempt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Computes the figures behind the admin overview dashboard, in one place, so the
 * Filament widgets stay thin and — crucially — the numbers can be asserted
 * directly in tests without rendering any UI.
 *
 * Every query runs through the tenant-scoped models (BelongsToTenant), so all of
 * this is automatically constrained to the current tenant set by BindCurrentTenant
 * on the panel. Nothing here reaches across tenants.
 *
 * Read-only aggregation over data that already exists (Sprints 2–5): employees,
 * enrolments, quiz attempts, certificates. No performance/attainment data — that
 * is V2.
 */
final class DashboardMetrics
{
    /** Enrolment statuses that represent a real, counted assignment (not a pending
     *  request, a cancellation, or a no-penalty waiver). The denominator for rates. */
    private const COUNTED_STATUSES = [
        EnrollmentStatus::Assigned,
        EnrollmentStatus::InProgress,
        EnrollmentStatus::Completed,
        EnrollmentStatus::Failed,
    ];

    public function activeHeadcount(): int
    {
        return Employee::query()->where('status', EmployeeStatus::Active->value)->count();
    }

    public function leavers(): int
    {
        return Employee::query()->where('status', EmployeeStatus::Exited->value)->count();
    }

    /** Assignments still in play — assigned or in progress. */
    public function activeEnrolments(): int
    {
        return Enrollment::query()
            ->whereIn('status', [EnrollmentStatus::Assigned->value, EnrollmentStatus::InProgress->value])
            ->count();
    }

    /** Open enrolments past their due date. */
    public function overdue(): int
    {
        return Enrollment::query()
            ->whereIn('status', [EnrollmentStatus::Assigned->value, EnrollmentStatus::InProgress->value])
            ->whereNotNull('due_at')
            ->where('due_at', '<', Carbon::now())
            ->count();
    }

    /** Completed as a percentage of all counted (real, resolved-or-in-play) enrolments. */
    public function completionRate(): int
    {
        $counted = Enrollment::query()
            ->whereIn('status', array_map(fn (EnrollmentStatus $s) => $s->value, self::COUNTED_STATUSES))
            ->count();

        if ($counted === 0) {
            return 0;
        }

        $completed = Enrollment::query()->where('status', EnrollmentStatus::Completed->value)->count();

        return (int) round($completed / $counted * 100);
    }

    /** Passing quiz attempts as a percentage of all graded attempts. */
    public function passRate(): int
    {
        $graded = QuizAttempt::query()->whereNotNull('submitted_at')->count();

        if ($graded === 0) {
            return 0;
        }

        $passed = QuizAttempt::query()->where('passed', true)->count();

        return (int) round($passed / $graded * 100);
    }

    public function certificatesIssued(): int
    {
        return Certificate::query()->count();
    }

    /** Certificates whose recertification deadline falls within the next $days. */
    public function recertDueSoon(int $days = 60): int
    {
        return Certificate::query()
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [Carbon::now(), Carbon::now()->addDays($days)])
            ->count();
    }

    /**
     * Enrolment counts by status, for the status doughnut. Only the statuses that
     * mean something on an operational board (a pending request or a cancellation
     * isn't "where learners are"). Zero-count statuses are dropped.
     *
     * @return array<string, int> status label => count
     */
    public function enrolmentsByStatus(): array
    {
        $counts = Enrollment::query()
            ->whereIn('status', array_map(fn (EnrollmentStatus $s) => $s->value, self::COUNTED_STATUSES))
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $out = [];
        foreach (self::COUNTED_STATUSES as $status) {
            $count = (int) ($counts[$status->value] ?? 0);
            if ($count > 0) {
                $out[$status->label()] = $count;
            }
        }

        return $out;
    }

    /**
     * Completions bucketed by week for the last $weeks weeks (oldest → newest).
     * Bucketed in PHP, not SQL, so the result is identical on sqlite (tests) and
     * MySQL (prod) — no DB-specific date functions.
     *
     * @return array<string, int> "j M" week-start label => completions
     */
    public function completionsByWeek(int $weeks = 8): array
    {
        $start = Carbon::now()->startOfWeek()->subWeeks($weeks - 1);

        // Seed every bucket to zero so quiet weeks still show on the chart.
        $buckets = [];
        for ($i = 0; $i < $weeks; $i++) {
            $buckets[$start->copy()->addWeeks($i)->format('Y-m-d')] = 0;
        }

        $completedAt = Enrollment::query()
            ->where('status', EnrollmentStatus::Completed->value)
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $start)
            ->pluck('completed_at');

        foreach ($completedAt as $ts) {
            $key = Carbon::parse($ts)->startOfWeek()->format('Y-m-d');
            if (array_key_exists($key, $buckets)) {
                $buckets[$key]++;
            }
        }

        // Relabel to a friendly week-start ("6 Jul").
        $labelled = [];
        foreach ($buckets as $date => $count) {
            $labelled[Carbon::parse($date)->format('j M')] = $count;
        }

        return $labelled;
    }

    /**
     * Top courses by number of counted enrolments, with their completion count —
     * "what is actually being taken".
     *
     * @return Collection<int, array{title: string, enrolments: int, completed: int}>
     */
    public function courseCoverage(int $limit = 5): Collection
    {
        $rows = Enrollment::query()
            ->whereIn('status', array_map(fn (EnrollmentStatus $s) => $s->value, self::COUNTED_STATUSES))
            ->selectRaw('course_id')
            ->selectRaw('count(*) as enrolments')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as completed', [EnrollmentStatus::Completed->value])
            ->groupBy('course_id')
            ->orderByDesc('enrolments')
            ->limit($limit)
            ->get();

        $titles = Course::query()
            ->whereIn('id', $rows->pluck('course_id'))
            ->pluck('title', 'id');

        // Read the aggregate columns via getAttribute — they're query-time aliases
        // (`enrolments`, `completed`), not declared model properties.
        return $rows->map(fn (Enrollment $row): array => [
            'title' => (string) ($titles[$row->getAttribute('course_id')] ?? 'Course'),
            'enrolments' => (int) $row->getAttribute('enrolments'),
            'completed' => (int) $row->getAttribute('completed'),
        ]);
    }
}
