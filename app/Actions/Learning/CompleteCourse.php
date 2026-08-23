<?php

declare(strict_types=1);

namespace App\Actions\Learning;

use App\Actions\Hris\EnqueueTrainingCompletion;
use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Notifications\CourseCompletedNotification;
use Illuminate\Support\Carbon;

/**
 * Marks an enrolment complete — the single course-completion transition every
 * path funnels through (today: passing the quiz).
 *
 * Kept as its own action, not inlined into quiz grading, because completion is
 * where the rest of the product loop hangs off: Sprint 5's later steps issue the
 * **certificate** and enqueue the HRIS **write-back** from right here, so there is
 * one authoritative place a course becomes "done".
 *
 * Idempotent and safe: only an *open* enrolment (assigned/in_progress) completes;
 * a terminal one (already completed/failed/waived/cancelled) is left untouched, so
 * re-submitting a passing quiz never double-completes or reopens a closed record.
 *
 * MUST run inside the enrolment's tenant context.
 */
final class CompleteCourse
{
    public function __construct(
        private readonly IssueCertificate $issueCertificate,
        private readonly EnqueueTrainingCompletion $enqueueTrainingCompletion,
    ) {}

    public function handle(Enrollment $enrollment): void
    {
        if (! $enrollment->status->isOpen()) {
            return;
        }

        $enrollment->forceFill([
            'status' => EnrollmentStatus::Completed,
            'completed_at' => Carbon::now(),
        ])->save();

        // Completion mints the certificate (idempotent — one per enrolment)...
        $certificate = $this->issueCertificate->handle($enrollment);

        // ...and stages the HRIS write-back in the same transaction (the outbox).
        // For ExampleHR this later parks as `unsupported`; for the mock it
        // succeeds — either way the learner's completion never waited on it.
        $this->enqueueTrainingCompletion->handle($enrollment, $certificate);

        // Tell the learner (if they have a login) — the closing note of the loop.
        $enrollment->loadMissing(['employee', 'course']);
        $enrollment->employee?->user?->notify(new CourseCompletedNotification(
            courseTitle: $enrollment->course->title,
            certificateId: $certificate->id,
            certificateSerial: $certificate->serial,
        ));
    }
}
