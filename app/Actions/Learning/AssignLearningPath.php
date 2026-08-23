<?php

declare(strict_types=1);

namespace App\Actions\Learning;

use App\Actions\Assignment\AssignCourse;
use App\Enums\EnrollmentSource;
use App\Models\Employee;
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
 * MUST run inside the employee's tenant context.
 */
final class AssignLearningPath
{
    public function __construct(private readonly AssignCourse $assignCourse) {}

    public function handle(
        LearningPath $path,
        Employee $employee,
        string $rationale,
        EnrollmentSource $source = EnrollmentSource::Manual,
        ?Carbon $dueAt = null,
        ?User $assignedBy = null,
        string $cycle = 'initial',
    ): PathEnrollment {
        $path->loadMissing('courses');

        $membership = PathEnrollment::query()->firstOrCreate(
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
            $this->assignCourse->handle(
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
            );
        }

        if ($isNew) {
            $employee->user?->notify(new PathAssignedNotification(
                pathName: $path->name,
                courseCount: $path->courses->count(),
                rationale: $rationale,
            ));
        }

        return $membership;
    }
}
