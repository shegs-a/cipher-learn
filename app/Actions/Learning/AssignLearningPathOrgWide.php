<?php

declare(strict_types=1);

namespace App\Actions\Learning;

use App\Assignment\AssignmentResult;
use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\LearningPath;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Fans a learning path out across the active workforce — everyone, or one
 * department — with a shared reason. Resolves the segment and delegates to
 * {@see AssignLearningPathToEmployees}, so it behaves exactly like any other bulk
 * path assignment (including the leave policy). Extracted from the Filament
 * resource, where this logic used to live: the policy must be enforced in the
 * domain layer, not in a UI class. MUST run in tenant context.
 */
final class AssignLearningPathOrgWide
{
    public function __construct(private readonly AssignLearningPathToEmployees $bulk) {}

    public function handle(
        LearningPath $path,
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
            path: $path,
            employees: $employees,
            rationale: $rationale,
            scope: $department !== null ? "department:{$department}" : 'org-wide',
            dueAt: $dueAt,
            assignedBy: $assignedBy,
        );
    }
}
