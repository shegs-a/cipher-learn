<?php

declare(strict_types=1);

namespace App\Assignment;

use App\Actions\Assignment\AssignCourse;
use App\Actions\Learning\AssignLearningPath;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\AssignmentBlockedNotification;
use App\Notifications\BulkAssignmentBlockedNotification;
use App\Support\Audit\Auditor;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything the assignment services need from the leave policy, in one place:
 * the eligibility verdict, the audit records, and the "you were blocked" notices.
 * {@see AssignCourse} and
 * {@see AssignLearningPath} both go through it, so the policy
 * behaves identically for courses and paths and cannot drift between them.
 *
 * The "subject" is the thing being assigned: a course or a learning path.
 */
final class LeaveGate
{
    public const COURSE = 'course';

    public const PATH = 'learning path';

    public function __construct(
        private readonly AssignmentEligibility $eligibility,
        private readonly Auditor $auditor,
    ) {}

    public function evaluate(Employee $employee): EligibilityDecision
    {
        return $this->eligibility->check($employee);
    }

    /** The assignment was refused: record why, in a form compliance can query. */
    public function recordBlocked(
        EligibilityDecision $decision,
        Employee $employee,
        string $subjectType,
        Model $subject,
        string $subjectName,
        ?User $by,
    ): void {
        $this->auditor->log(
            'assignment.blocked',
            $employee,
            newValues: $this->details($decision, $employee, $subjectType, $subject, $subjectName, $by),
        );
    }

    /** The assignment went ahead although the employee was on leave (policy allows it). */
    public function recordAllowedOnLeave(
        EligibilityDecision $decision,
        Employee $employee,
        string $subjectType,
        Model $subject,
        string $subjectName,
        ?User $by,
    ): void {
        $this->auditor->log(
            'assignment.allowed_on_leave',
            $employee,
            newValues: $this->details($decision, $employee, $subjectType, $subject, $subjectName, $by),
        );
    }

    /** One notice, to whoever initiated it, for a single blocked assignment. */
    public function notifyBlocked(AssignmentItemResult $result, string $subjectType, string $subjectName, ?User $by): void
    {
        $by?->notify(new AssignmentBlockedNotification(
            employeeName: $result->employee->full_name,
            assignmentType: $subjectType,
            subjectName: $subjectName,
            leavePeriod: $result->leavePeriod(),
        ));
    }

    /**
     * One summary notice for a whole bulk operation (not one per employee), plus a
     * single audit row carrying the counts and the full blocked list with leave dates.
     */
    public function concludeBulk(
        AssignmentResult $result,
        string $subjectType,
        Model $subject,
        string $subjectName,
        string $scope,
        ?User $by,
    ): void {
        $this->auditor->log('assignment.bulk_completed', $subject, newValues: [
            'subject_type' => $subjectType,
            'subject_id' => $subject->getKey(),
            'subject_name' => $subjectName,
            'scope' => $scope,
            'initiated_by_user_id' => $by?->getKey(),
        ] + $result->toArray());

        if ($result->hasBlocked()) {
            $by?->notify(new BulkAssignmentBlockedNotification(
                assignmentType: $subjectType,
                subjectName: $subjectName,
                total: $result->total(),
                assigned: $result->assigned(),
                blocked: $result->blocked(),
                blockedNames: array_map(
                    fn (AssignmentItemResult $i): string => $i->employee->full_name,
                    $result->blockedItems(),
                ),
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function details(
        EligibilityDecision $decision,
        Employee $employee,
        string $subjectType,
        Model $subject,
        string $subjectName,
        ?User $by,
    ): array {
        return [
            'subject_type' => $subjectType,
            'subject_id' => $subject->getKey(),
            'subject_name' => $subjectName,
            'employee_id' => $employee->getKey(),
            'employee_name' => $employee->full_name,
            'leave_start' => $decision->leaveStart(),
            'leave_end' => $decision->leaveEnd(),
            'leave_type' => $decision->leave?->leave_type,
            'policy_allows_assignment_on_leave' => $decision->policyAllowsLeave,
            'block_reason' => $decision->blockReason?->value,
            'leave_data_stale' => $decision->leaveDataStale,
            'initiated_by_user_id' => $by?->getKey(),
        ];
    }
}
