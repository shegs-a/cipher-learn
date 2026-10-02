<?php

declare(strict_types=1);

namespace App\Actions\Assignment;

use App\Assignment\AssignmentItemResult;
use App\Assignment\AssignmentResult;
use App\Assignment\LeaveGate;
use App\Enums\AssignmentSkipReason;
use App\Enums\EmployeeStatus;
use App\Models\Course;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Assigns one course to a set of employees and reports what happened to each.
 *
 * The bulk engine behind every multi-person course assignment — a hand-picked
 * selection, a department, the whole organisation. It is deliberately resilient:
 * one employee being on leave, already enrolled, or even failing outright never
 * fails the batch; everyone else is still processed. The returned
 * {@see AssignmentResult} separates assigned, blocked (on leave) and skipped
 * (already assigned / other).
 *
 * Per-employee "blocked" notices are suppressed; the initiator gets ONE summary at
 * the end instead, and the full list lives in the result and the audit trail.
 *
 * MUST run inside the tenant's context.
 */
final class AssignCourseToEmployees
{
    public function __construct(
        private readonly AssignCourse $assignCourse,
        private readonly LeaveGate $gate,
    ) {}

    /**
     * @param  iterable<Employee>  $employees
     * @param  string  $scope  how the set was chosen, for the evidence and the audit row
     *                         ("org-wide", "department:Finance", "selection")
     */
    public function handle(
        Course $course,
        iterable $employees,
        string $rationale,
        string $scope = 'selection',
        ?Carbon $dueAt = null,
        ?User $assignedBy = null,
    ): AssignmentResult {
        $result = new AssignmentResult;

        foreach ($employees as $employee) {
            $result->add($this->assignOne($course, $employee, $rationale, $scope, $dueAt, $assignedBy));
        }

        $this->gate->concludeBulk($result, LeaveGate::COURSE, $course, $course->title, $scope, $assignedBy);

        return $result;
    }

    private function assignOne(
        Course $course,
        Employee $employee,
        string $rationale,
        string $scope,
        ?Carbon $dueAt,
        ?User $assignedBy,
    ): AssignmentItemResult {
        // Leavers cannot be assigned training (a hand-picked selection can include them).
        if ($employee->status === EmployeeStatus::Exited) {
            return AssignmentItemResult::skipped($employee, AssignmentSkipReason::InvalidEmployee);
        }

        try {
            return $this->assignCourse->attempt(
                employee: $employee,
                course: $course,
                rationale: $rationale,
                dueAt: $dueAt,
                assignedBy: $assignedBy,
                evidence: ['scope' => $scope],
                notifyBlocked: false,
            );
        } catch (Throwable $e) {
            // One bad record must not abandon the batch: report it and move on.
            report($e);

            return AssignmentItemResult::skipped($employee, AssignmentSkipReason::Other);
        }
    }
}
