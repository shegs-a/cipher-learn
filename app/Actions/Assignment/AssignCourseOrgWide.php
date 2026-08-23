<?php

declare(strict_types=1);

namespace App\Actions\Assignment;

use App\Enums\EmployeeStatus;
use App\Models\Course;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * HR assigns a course to a whole segment of the org — everyone, or one
 * department — with a shared reason (e.g. a mandatory compliance refresher).
 *
 * A thin fan-out over {@see AssignCourse}: same per-person semantics (idempotent,
 * never clobbers a completion), applied to every active employee in the segment.
 * Returns how many were assigned. MUST run in tenant context.
 */
final class AssignCourseOrgWide
{
    public function handle(
        Course $course,
        string $rationale,
        ?string $department = null,
        ?Carbon $dueAt = null,
        ?User $assignedBy = null,
    ): int {
        $employees = Employee::query()
            ->where('status', EmployeeStatus::Active->value)
            ->when($department !== null && $department !== '', fn ($q) => $q->where('department', $department))
            ->get();

        $assign = app(AssignCourse::class);

        foreach ($employees as $employee) {
            $assign->handle(
                employee: $employee,
                course: $course,
                rationale: $rationale,
                dueAt: $dueAt,
                assignedBy: $assignedBy,
                evidence: ['scope' => $department !== null && $department !== '' ? "department:{$department}" : 'org-wide'],
            );
        }

        return $employees->count();
    }
}
