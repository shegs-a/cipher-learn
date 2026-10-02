<?php

declare(strict_types=1);

namespace App\Actions\Learning;

use App\Assignment\AssignmentItemResult;
use App\Assignment\AssignmentResult;
use App\Assignment\LeaveGate;
use App\Enums\AssignmentSkipReason;
use App\Enums\EmployeeStatus;
use App\Enums\EnrollmentSource;
use App\Models\Employee;
use App\Models\LearningPath;
use App\Models\User;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Puts a set of employees on a learning path and reports what happened to each —
 * the bulk counterpart of {@see AssignLearningPath}, covering a hand-picked
 * selection, a department, or the whole organisation.
 *
 * Resilient by design: someone being on leave, already on the path, or failing
 * outright never fails the batch. A blocked employee gets NOTHING of the path —
 * no membership, no course enrolments (that guarantee lives in AssignLearningPath).
 * The initiator gets one summary notification, not one per blocked employee.
 *
 * MUST run inside the tenant's context.
 */
final class AssignLearningPathToEmployees
{
    public function __construct(
        private readonly AssignLearningPath $assignPath,
        private readonly LeaveGate $gate,
    ) {}

    /**
     * @param  iterable<Employee>  $employees
     * @param  string  $scope  "org-wide", "department:Finance" or "selection"
     */
    public function handle(
        LearningPath $path,
        iterable $employees,
        string $rationale,
        string $scope = 'selection',
        ?Carbon $dueAt = null,
        ?User $assignedBy = null,
    ): AssignmentResult {
        $result = new AssignmentResult;

        foreach ($employees as $employee) {
            $result->add($this->assignOne($path, $employee, $rationale, $dueAt, $assignedBy));
        }

        $this->gate->concludeBulk($result, LeaveGate::PATH, $path, $path->name, $scope, $assignedBy);

        return $result;
    }

    private function assignOne(
        LearningPath $path,
        Employee $employee,
        string $rationale,
        ?Carbon $dueAt,
        ?User $assignedBy,
    ): AssignmentItemResult {
        if ($employee->status === EmployeeStatus::Exited) {
            return AssignmentItemResult::skipped($employee, AssignmentSkipReason::InvalidEmployee);
        }

        try {
            return $this->assignPath->attempt(
                path: $path,
                employee: $employee,
                rationale: $rationale,
                source: EnrollmentSource::Manual,
                dueAt: $dueAt,
                assignedBy: $assignedBy,
                notifyBlocked: false,
            );
        } catch (Throwable $e) {
            report($e);

            return AssignmentItemResult::skipped($employee, AssignmentSkipReason::Other);
        }
    }
}
