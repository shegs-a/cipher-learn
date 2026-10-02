<?php

declare(strict_types=1);

namespace App\Actions\Assignment;

use App\Assignment\AssignmentResult;
use App\Enums\EmployeeStatus;
use App\Models\Course;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * HR assigns a course to a whole segment of the org — everyone, or one
 * department — with a shared reason (e.g. a mandatory compliance refresher).
 *
 * Resolves the segment (every active employee, or one department's) and hands it to
 * {@see AssignCourseToEmployees}, which applies the same per-person semantics as
 * {@see AssignCourse} — idempotent, never clobbers a completion, honours the leave
 * policy — and returns a structured {@see AssignmentResult}. MUST run in tenant
 * context.
 */
final class AssignCourseOrgWide
{
    public function __construct(private readonly AssignCourseToEmployees $bulk) {}

    public function handle(
        Course $course,
        string $rationale,
        ?string $department = null,
        ?Carbon $dueAt = null,
        ?User $assignedBy = null,
    ): AssignmentResult {
        $department = $department !== null && $department !== '' ? $department : null;

        $employees = Employee::query()
            ->where('status', EmployeeStatus::Active->value)
            ->when($department !== null, fn ($q) => $q->where('department', $department))
            ->get();

        return $this->bulk->handle(
            course: $course,
            employees: $employees,
            rationale: $rationale,
            scope: $department !== null ? "department:{$department}" : 'org-wide',
            dueAt: $dueAt,
            assignedBy: $assignedBy,
        );
    }
}
