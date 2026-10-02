<?php

declare(strict_types=1);

namespace App\Actions\Assignment;

use App\Assignment\AssignmentBlockedException;
use App\Assignment\AssignmentItemResult;
use App\Assignment\EligibilityDecision;
use App\Assignment\LeaveGate;
use App\Enums\AssignmentSkipReason;
use App\Enums\EnrollmentSource;
use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\AccessRequestedNotification;
use App\Notifications\CourseAssignedNotification;
use Illuminate\Support\Carbon;

/**
 * Assigns a course to an employee — the one place an enrolment is created or
 * updated. Every manual path calls this: a manager assigning a report, HR
 * assigning org-wide, a learner requesting access, and an approval turning a
 * request into a real assignment.
 *
 * An assignment is an enrolment carrying a **written reason** (`rationale`) —
 * that is the product, not metadata, so it is required here.
 *
 * Idempotent on `(tenant, employee, course, cycle)` — the same uniqueness the
 * schema enforces. Re-assigning the same course in the same cycle updates the
 * existing row rather than colliding, which is exactly how a **request →
 * approval** works: the pending `requested` enrolment is flipped to `assigned`.
 * A terminal enrolment (completed/failed/waived) is never clobbered.
 *
 * LEAVE POLICY. Before a NEW assignment is created (including a request being
 * approved into one), {@see LeaveGate} decides whether the employee is eligible.
 * If they are on leave and the tenant disallows that, nothing is written, the
 * learner is not notified, and the outcome is `Blocked`. The check lives here —
 * the shared service — so every workflow inherits it; no UI has to remember.
 * A learner's own *request* is not an assignment and is never gated.
 *
 * Two entry points:
 *  - {@see attempt()} returns an {@see AssignmentItemResult} (assigned / blocked /
 *    skipped) for callers that render the outcome. Prefer it.
 *  - {@see handle()} returns the Enrollment and throws {@see AssignmentBlockedException}
 *    when blocked, so a caller that expects a model can never silently proceed as
 *    if a blocked assignment had happened.
 *
 * MUST run inside the employee's tenant context (the BelongsToTenant scope stamps
 * and filters on it).
 */
final class AssignCourse
{
    public function __construct(private readonly LeaveGate $gate) {}

    /**
     * @param  array<string, mixed>  $evidence  the data behind the reason (KPI, request note…)
     *
     * @throws AssignmentBlockedException when the employee is on leave and policy disallows it
     */
    public function handle(
        Employee $employee,
        Course $course,
        string $rationale,
        EnrollmentSource $source = EnrollmentSource::Manual,
        EnrollmentStatus $status = EnrollmentStatus::Assigned,
        ?Carbon $dueAt = null,
        ?User $assignedBy = null,
        array $evidence = [],
        string $cycle = 'initial',
        bool $notify = true,
    ): Enrollment {
        $result = $this->attempt(
            employee: $employee,
            course: $course,
            rationale: $rationale,
            source: $source,
            status: $status,
            dueAt: $dueAt,
            assignedBy: $assignedBy,
            evidence: $evidence,
            cycle: $cycle,
            notify: $notify,
        );

        if ($result->isBlocked()) {
            throw new AssignmentBlockedException($result, $course->title);
        }

        // Assigned and skipped outcomes always carry the enrolment (see attempt()).
        return $result->enrollment ?? throw new \LogicException('An unblocked assignment must carry its enrolment.');
    }

    /**
     * Try to assign, and report exactly what happened.
     *
     * @param  array<string, mixed>  $evidence  the data behind the reason (KPI, request note…)
     * @param  bool  $notify  send the learner's "assigned" notice
     * @param  bool  $notifyBlocked  notify the initiator when blocked (bulk runners pass false and send one summary)
     * @param  EligibilityDecision|null  $leaveDecision  a verdict an orchestrating service (a learning path) already
     *                                                   reached for this employee. It is honoured instead of
     *                                                   re-checking, and the orchestrator owns the audit record,
     *                                                   so a path of N courses writes one audit row, not N.
     */
    public function attempt(
        Employee $employee,
        Course $course,
        string $rationale,
        EnrollmentSource $source = EnrollmentSource::Manual,
        EnrollmentStatus $status = EnrollmentStatus::Assigned,
        ?Carbon $dueAt = null,
        ?User $assignedBy = null,
        array $evidence = [],
        string $cycle = 'initial',
        bool $notify = true,
        bool $notifyBlocked = true,
        ?EligibilityDecision $leaveDecision = null,
    ): AssignmentItemResult {
        $enrollment = Enrollment::query()
            ->where('employee_id', $employee->id)
            ->where('course_id', $course->id)
            ->where('cycle', $cycle)
            ->first();

        // Never overwrite a finished record — completion is history.
        if ($enrollment !== null && in_array($enrollment->status, [
            EnrollmentStatus::Completed,
            EnrollmentStatus::Failed,
            EnrollmentStatus::Waived,
        ], true)) {
            return AssignmentItemResult::skipped($employee, AssignmentSkipReason::TerminalStatus, $enrollment);
        }

        // Is this a genuinely NEW assignment (as opposed to a re-assign of an open
        // one, or a learner's request)? Only those are subject to the leave policy.
        $isNewAssignment = $status === EnrollmentStatus::Assigned
            && ($enrollment === null || in_array($enrollment->status, [EnrollmentStatus::Requested, EnrollmentStatus::Cancelled], true));

        // A learner enrolling THEMSELVES is their own choice, not an assignment by an
        // admin or manager, so the leave policy does not apply to it.
        $gated = $isNewAssignment && $source !== EnrollmentSource::SelfEnrolled;

        $decision = null;

        if ($gated) {
            $decision = $leaveDecision ?? $this->gate->evaluate($employee);

            if ($decision->blocked) {
                $this->gate->recordBlocked($decision, $employee, LeaveGate::COURSE, $course, $course->title, $assignedBy);

                $result = AssignmentItemResult::blocked($employee, $decision);

                if ($notifyBlocked) {
                    $this->gate->notifyBlocked($result, LeaveGate::COURSE, $course->title, $assignedBy);
                }

                return $result;
            }

            if ($decision->isAllowedWhileOnLeave()) {
                // Make it findable on the enrolment itself, not only in the audit trail.
                $evidence['assigned_during_leave'] = [
                    'start' => $decision->leaveStart(),
                    'end' => $decision->leaveEnd(),
                ];
            }
        }

        $written = $this->write($employee, $course, $rationale, $source, $status, $dueAt, $assignedBy, $evidence, $cycle, $notify, $enrollment);

        if (! $isNewAssignment) {
            // A request being filed is recorded as assigned-to-the-queue; an open
            // enrolment being re-assigned adds nothing new.
            return $status === EnrollmentStatus::Assigned
                ? AssignmentItemResult::skipped($employee, AssignmentSkipReason::AlreadyAssigned, $written)
                : AssignmentItemResult::assigned($employee, $written);
        }

        if ($leaveDecision === null && $decision?->isAllowedWhileOnLeave()) {
            $this->gate->recordAllowedOnLeave($decision, $employee, LeaveGate::COURSE, $course, $course->title, $assignedBy);
        }

        return AssignmentItemResult::assigned($employee, $written, decision: $decision);
    }

    /**
     * Create or update the enrolment and send the right notification. The leave
     * policy has already been applied by the time we get here.
     *
     * @param  array<string, mixed>  $evidence
     */
    private function write(
        Employee $employee,
        Course $course,
        string $rationale,
        EnrollmentSource $source,
        EnrollmentStatus $status,
        ?Carbon $dueAt,
        ?User $assignedBy,
        array $evidence,
        string $cycle,
        bool $notify,
        ?Enrollment $enrollment,
    ): Enrollment {
        $attributes = [
            'source' => $source,
            'method' => 'manual', // how the course was chosen; the engine (S6) sets rule/model/lexical
            'rationale' => $rationale,
            'evidence' => $evidence,
            'status' => $status,
            'due_at' => $dueAt,
            'assigned_by_user_id' => $assignedBy?->id,
        ];

        if ($enrollment === null) {
            $created = Enrollment::create($attributes + [
                'employee_id' => $employee->id,
                'course_id' => $course->id,
                'cycle' => $cycle,
            ]);

            if ($notify) {
                $this->notify($created, $employee, $course, $rationale, $dueAt, previousStatus: null);
            }

            return $created;
        }

        $previousStatus = $enrollment->status;
        $enrollment->update($attributes);

        if ($notify) {
            $this->notify($enrollment, $employee, $course, $rationale, $dueAt, $previousStatus);
        }

        return $enrollment;
    }

    /**
     * Fire the right notification for what actually changed — and only for a real
     * transition, so an idempotent re-assign (or org-wide fan-out over people who
     * already have the course) never re-notifies:
     *   • a new assignment, or a request just approved into one → the learner;
     *   • a brand-new request → the learner's manager (the approver).
     * A learner/manager with no login is simply skipped.
     */
    private function notify(
        Enrollment $enrollment,
        Employee $employee,
        Course $course,
        string $rationale,
        ?Carbon $dueAt,
        ?EnrollmentStatus $previousStatus,
    ): void {
        $wasCreated = $previousStatus === null;

        $becameAssigned = $enrollment->status === EnrollmentStatus::Assigned
            && ($wasCreated || $previousStatus === EnrollmentStatus::Requested);

        if ($becameAssigned) {
            $employee->user?->notify(new CourseAssignedNotification(
                courseTitle: $course->title,
                rationale: $rationale,
                dueAt: $dueAt?->format('j M Y'),
                enrollmentId: $enrollment->id,
            ));

            return;
        }

        if ($wasCreated && $enrollment->status === EnrollmentStatus::Requested) {
            $employee->manager?->user?->notify(new AccessRequestedNotification(
                learnerName: $employee->full_name,
                courseTitle: $course->title,
                note: $rationale,
            ));
        }
    }

    /**
     * A learner's request to take a course they were not assigned. A pending
     * enrolment awaiting a manager/admin decision — no learning obligation yet, so
     * the leave policy (which governs assignments) does not apply to it.
     */
    public function request(Employee $employee, Course $course, string $note): Enrollment
    {
        return $this->handle(
            employee: $employee,
            course: $course,
            rationale: $note,
            source: EnrollmentSource::SelfEnrolled,
            status: EnrollmentStatus::Requested,
            evidence: ['requested_by' => 'self'],
        );
    }
}
