<?php

declare(strict_types=1);

namespace App\Actions\Learning;

use App\Actions\Assignment\AssignCourse;
use App\Assignment\AssignmentBlockedException;
use App\Assignment\AssignmentItemResult;
use App\Assignment\LeaveGate;
use App\Enums\AssignmentSkipReason;
use App\Enums\EnrollmentSource;
use App\Enums\EnrollmentStatus;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\LearningPath;
use App\Models\PathEnrollment;
use App\Models\User;
use App\Notifications\PathAssignedNotification;
use Illuminate\Support\Carbon;

/**
 * Puts an employee on a learning path — the one place a path is assigned, whether
 * by an admin/manager or a learner self-enrolling.
 *
 * A path is a **grouping**, so this fans out: it records the path membership
 * ({@see PathEnrollment}) and assigns **every course in the path** as a normal
 * enrolment via {@see AssignCourse}, tagged (in `evidence`) with the path. Each
 * course therefore keeps its own record, progress, quiz attempts, certificate and
 * HR write-back — nothing about the per-course journey changes. Path certificates
 * are a later sprint; for now each course issues its own certificate as usual.
 *
 * Idempotent: the path membership is upserted and `AssignCourse` is idempotent, so
 * re-assigning a path adds nothing and never re-notifies. Per-course "assigned"
 * notifications are **suppressed** — the learner gets a single
 * {@see PathAssignedNotification} instead of one email per course.
 *
 * LEAVE POLICY. The eligibility check happens ONCE, up front, before the path
 * membership or any course enrolment is created. If the employee is blocked, nothing
 * at all is written — no membership, no per-course enrolments, no notification — so
 * a blocked employee can never end up with half a path. When the check passes, the
 * single verdict is handed to every per-course assignment (and audited once).
 *
 * A learner enrolling THEMSELVES in a path is their own choice, not an assignment
 * by an admin, and is not subject to the leave policy ({@see LeaveGate}).
 *
 * MUST run inside the employee's tenant context.
 */
final class AssignLearningPath
{
    public function __construct(
        private readonly AssignCourse $assignCourse,
        private readonly LeaveGate $gate,
    ) {}

    /**
     * @throws AssignmentBlockedException when the employee is on leave and policy disallows it
     */
    public function handle(
        LearningPath $path,
        Employee $employee,
        string $rationale,
        EnrollmentSource $source = EnrollmentSource::Manual,
        ?Carbon $dueAt = null,
        ?User $assignedBy = null,
        string $cycle = 'initial',
    ): PathEnrollment {
        $result = $this->attempt($path, $employee, $rationale, $source, $dueAt, $assignedBy, $cycle);

        if ($result->isBlocked()) {
            throw new AssignmentBlockedException($result, $path->name);
        }

        return $result->pathEnrollment ?? throw new \LogicException('An unblocked path assignment must carry its membership.');
    }

    /**
     * Try to put the employee on the path, and report exactly what happened.
     *
     * @param  bool  $notifyBlocked  notify the initiator when blocked (bulk runners pass false and send one summary)
     */
    public function attempt(
        LearningPath $path,
        Employee $employee,
        string $rationale,
        EnrollmentSource $source = EnrollmentSource::Manual,
        ?Carbon $dueAt = null,
        ?User $assignedBy = null,
        string $cycle = 'initial',
        bool $notifyBlocked = true,
    ): AssignmentItemResult {
        $path->loadMissing('courses');

        $existing = PathEnrollment::query()
            ->where('learning_path_id', $path->id)
            ->where('employee_id', $employee->id)
            ->where('cycle', $cycle)
            ->first();

        // Self-enrolment is the learner's own choice — not gated. Anything an admin or
        // manager initiates is.
        $gated = $source !== EnrollmentSource::SelfEnrolled;

        $decision = null;
        $assignsSomething = $this->wouldAssignSomething($existing, $path, $employee, $cycle);

        if ($gated && $assignsSomething) {
            $decision = $this->gate->evaluate($employee);

            if ($decision->blocked) {
                // Nothing has been written yet — and nothing will be.
                $this->gate->recordBlocked($decision, $employee, LeaveGate::PATH, $path, $path->name, $assignedBy);

                $result = AssignmentItemResult::blocked($employee, $decision);

                if ($notifyBlocked) {
                    $this->gate->notifyBlocked($result, LeaveGate::PATH, $path->name, $assignedBy);
                }

                return $result;
            }
        }

        $membership = $existing ?? PathEnrollment::query()->firstOrCreate(
            [
                'learning_path_id' => $path->id,
                'employee_id' => $employee->id,
                'cycle' => $cycle,
            ],
            [
                'source' => $source->value,
                'assigned_by_user_id' => $assignedBy?->id,
            ],
        );

        // Whether this call actually enrolled the learner on the path (vs a re-run)
        // — only notify on a genuinely new membership.
        $isNew = $membership->wasRecentlyCreated;

        foreach ($path->courses as $course) {
            $this->assignCourse->attempt(
                employee: $employee,
                course: $course,
                rationale: $rationale,
                source: $source,
                dueAt: $dueAt,
                assignedBy: $assignedBy,
                evidence: ['learning_path_id' => $path->id, 'learning_path_name' => $path->name],
                cycle: $cycle,
                // Suppress the per-course notice — one path notification is sent below.
                notify: false,
                // The path already reached its verdict; don't re-check or re-audit per course.
                leaveDecision: $decision,
            );
        }

        if ($isNew) {
            $employee->user?->notify(new PathAssignedNotification(
                pathName: $path->name,
                courseCount: $path->courses->count(),
                rationale: $rationale,
            ));
        }

        if ($decision?->isAllowedWhileOnLeave()) {
            $this->gate->recordAllowedOnLeave($decision, $employee, LeaveGate::PATH, $path, $path->name, $assignedBy);
        }

        // A re-run that added nothing is a skip, not an assignment.
        return $assignsSomething
            ? AssignmentItemResult::assigned($employee, pathEnrollment: $membership, decision: $decision)
            : AssignmentItemResult::skipped($employee, AssignmentSkipReason::AlreadyAssigned, pathEnrollment: $membership);
    }

    /**
     * Would this call create a membership, or enrol the employee on a course of the
     * path they do not yet hold? Only then is there something for the leave policy
     * to decide; re-running an already-complete assignment must not be refused (or
     * reported as blocked) merely because the person has since gone on leave.
     */
    private function wouldAssignSomething(?PathEnrollment $existing, LearningPath $path, Employee $employee, string $cycle): bool
    {
        if ($existing === null) {
            return true;
        }

        /** @var array<string, EnrollmentStatus> $held course id => status */
        $held = Enrollment::query()
            ->where('employee_id', $employee->id)
            ->where('cycle', $cycle)
            ->whereIn('course_id', $path->courses->pluck('id'))
            ->get()
            ->mapWithKeys(fn (Enrollment $e): array => [(string) $e->course_id => $e->status])
            ->all();

        foreach ($path->courses as $course) {
            $status = $held[(string) $course->id] ?? null;

            if ($status === null || in_array($status, [EnrollmentStatus::Requested, EnrollmentStatus::Cancelled], true)) {
                return true;
            }
        }

        return false;
    }
}
