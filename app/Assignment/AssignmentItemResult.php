<?php

declare(strict_types=1);

namespace App\Assignment;

use App\Enums\AssignmentBlockReason;
use App\Enums\AssignmentOutcome;
use App\Enums\AssignmentSkipReason;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\PathEnrollment;

/**
 * The outcome of assigning ONE thing (a course or a learning path) to ONE employee.
 * Assigned, blocked and skipped are distinct outcomes: "already has it" is never
 * reported as a refusal.
 */
final readonly class AssignmentItemResult
{
    private function __construct(
        public Employee $employee,
        public AssignmentOutcome $outcome,
        public ?AssignmentBlockReason $blockReason = null,
        public ?AssignmentSkipReason $skipReason = null,
        public ?Enrollment $enrollment = null,
        public ?PathEnrollment $pathEnrollment = null,
        public ?string $leaveStart = null,
        public ?string $leaveEnd = null,
        public ?string $leaveType = null,
        /** The tenant allows it, and the employee was on leave when assigned. */
        public bool $assignedWhileOnLeave = false,
        /** The leave check ran on leave data older than the freshness threshold. */
        public bool $leaveDataStale = false,
    ) {}

    public static function assigned(
        Employee $employee,
        ?Enrollment $enrollment = null,
        ?PathEnrollment $pathEnrollment = null,
        ?EligibilityDecision $decision = null,
    ): self {
        return new self(
            employee: $employee,
            outcome: AssignmentOutcome::Assigned,
            enrollment: $enrollment,
            pathEnrollment: $pathEnrollment,
            leaveStart: $decision?->leaveStart(),
            leaveEnd: $decision?->leaveEnd(),
            leaveType: $decision?->leave?->leave_type,
            assignedWhileOnLeave: $decision?->isAllowedWhileOnLeave() ?? false,
            leaveDataStale: $decision?->leaveDataStale ?? false,
        );
    }

    public static function blocked(Employee $employee, EligibilityDecision $decision): self
    {
        return new self(
            employee: $employee,
            outcome: AssignmentOutcome::Blocked,
            blockReason: $decision->blockReason,
            leaveStart: $decision->leaveStart(),
            leaveEnd: $decision->leaveEnd(),
            leaveType: $decision->leave?->leave_type,
            leaveDataStale: $decision->leaveDataStale,
        );
    }

    public static function skipped(
        Employee $employee,
        AssignmentSkipReason $reason,
        ?Enrollment $enrollment = null,
        ?PathEnrollment $pathEnrollment = null,
    ): self {
        return new self(
            employee: $employee,
            outcome: AssignmentOutcome::Skipped,
            skipReason: $reason,
            enrollment: $enrollment,
            pathEnrollment: $pathEnrollment,
        );
    }

    public function isAssigned(): bool
    {
        return $this->outcome === AssignmentOutcome::Assigned;
    }

    public function isBlocked(): bool
    {
        return $this->outcome === AssignmentOutcome::Blocked;
    }

    public function isSkipped(): bool
    {
        return $this->outcome === AssignmentOutcome::Skipped;
    }

    /**
     * A sentence for the person who initiated the assignment, e.g.
     * "Jane Doe is on leave (28 Sep 2026 – 7 Oct 2026), so “Advanced Leadership” was not assigned."
     */
    public function message(string $subjectName): string
    {
        return match ($this->outcome) {
            AssignmentOutcome::Blocked => sprintf(
                '%s is on leave (%s), so “%s” was not assigned.',
                $this->employee->full_name,
                $this->leavePeriod(),
                $subjectName,
            ),
            AssignmentOutcome::Skipped => sprintf(
                '%s: %s.',
                $this->employee->full_name,
                strtolower(($this->skipReason ?? AssignmentSkipReason::Other)->label()),
            ),
            AssignmentOutcome::Assigned => sprintf('“%s” was assigned to %s.', $subjectName, $this->employee->full_name),
        };
    }

    /** "28 Sep 2026 – 7 Oct 2026" (or "dates unknown" if the snapshot lacks them). */
    public function leavePeriod(): string
    {
        if ($this->leaveStart === null || $this->leaveEnd === null) {
            return 'dates unknown';
        }

        return date('j M Y', (int) strtotime($this->leaveStart)).' – '.date('j M Y', (int) strtotime($this->leaveEnd));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'employee_id' => $this->employee->getKey(),
            'employee' => $this->employee->full_name,
            'outcome' => $this->outcome->value,
            'reason' => ($this->blockReason ?? $this->skipReason)?->value,
            'leave_start' => $this->leaveStart,
            'leave_end' => $this->leaveEnd,
            'leave_type' => $this->leaveType,
        ];
    }
}
