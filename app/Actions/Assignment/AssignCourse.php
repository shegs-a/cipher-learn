<?php

declare(strict_types=1);

namespace App\Actions\Assignment;

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
 * MUST run inside the employee's tenant context (the BelongsToTenant scope stamps
 * and filters on it).
 */
final class AssignCourse
{
    /**
     * @param  array<string, mixed>  $evidence  the data behind the reason (KPI, request note…)
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
        $enrollment = Enrollment::query()
            ->where('employee_id', $employee->id)
            ->where('course_id', $course->id)
            ->where('cycle', $cycle)
            ->first();

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

        // Never overwrite a finished record — completion is history.
        if (in_array($enrollment->status, [
            EnrollmentStatus::Completed,
            EnrollmentStatus::Failed,
            EnrollmentStatus::Waived,
        ], true)) {
            return $enrollment;
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
     * enrolment awaiting a manager/admin decision — no learning obligation yet.
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
