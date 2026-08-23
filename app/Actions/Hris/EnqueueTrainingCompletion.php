<?php

declare(strict_types=1);

namespace App\Actions\Hris;

use App\Actions\Learning\CompleteCourse;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Models\OutboxEvent;
use Illuminate\Support\Carbon;

/**
 * Records a completed course as a `training.completion` **outbox event** — the
 * write side of the product loop, staged for the HRIS write-back.
 *
 * This is the transactional-outbox pattern: completion and the intent to write it
 * back are committed together, in the same database transaction as
 * {@see CompleteCourse}. The learner's request never waits
 * on (or fails because of) a flaky external HR API — a separate worker
 * ({@see ProcessOutbox}) drains the event later. If ExampleHR never ships a
 * write-back endpoint, the event simply parks as `unsupported`; nothing is lost.
 *
 * Idempotent: one event per enrolment, so a re-completion doesn't double-enqueue.
 *
 * MUST run inside the enrolment's tenant context (the row is tenant-stamped, and
 * ProcessOutbox resolves the adapter from that tenant later).
 */
final class EnqueueTrainingCompletion
{
    public function handle(Enrollment $enrollment, ?Certificate $certificate = null): OutboxEvent
    {
        $enrollment->loadMissing(['employee', 'course']);

        // Guard against a second event for the same completion.
        $existing = OutboxEvent::query()
            ->where('type', 'training.completion')
            ->where('payload->enrollment_id', $enrollment->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // The score is the passing attempt's, where the course was assessed.
        $score = $enrollment->quizAttempts()
            ->where('passed', true)
            ->latest('submitted_at')
            ->value('score');

        return OutboxEvent::create([
            'type' => 'training.completion',
            'status' => 'pending',
            'available_at' => Carbon::now(),
            'payload' => [
                'enrollment_id' => $enrollment->id,
                // The HRIS's own key for the person — null for a non-HRIS
                // (manually-added) employee, which ProcessOutbox treats as
                // unsupported since there is no HR record to write against.
                'employee_external_id' => $enrollment->employee?->external_id,
                'course_title' => $enrollment->course->title,
                'completed_at' => ($enrollment->completed_at ?? Carbon::now())->toIso8601String(),
                'score' => $score !== null ? (int) $score : null,
                'certificate_serial' => $certificate?->serial,
            ],
        ]);
    }
}
