<?php

declare(strict_types=1);

namespace App\Actions\Learning;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Marks a single lesson complete for an enrolment — the one place a
 * `lesson_progress` row is written, mirroring how `AssignCourse` owns enrolment
 * writes.
 *
 * Two things happen together, in one transaction:
 *  1. The lesson's progress row is upserted to `completed` (idempotent on the
 *     schema's `unique(enrollment_id, lesson_id)` — re-marking a done lesson is a
 *     no-op, never a duplicate or a collision).
 *  2. The **first** sign of activity flips the enrolment `assigned → in_progress`
 *     and stamps `started_at`. A terminal enrolment (completed/failed/waived) or a
 *     still-`requested` one is never transitioned by this — you cannot "take" a
 *     course you were never actually assigned, nor re-open a closed one.
 *
 * MUST run inside the enrolment's tenant context (the BelongsToTenant scope both
 * stamps the new progress row's `tenant_id` and filters the lookup).
 */
final class CompleteLesson
{
    public function handle(Enrollment $enrollment, Lesson $lesson): LessonProgress
    {
        return DB::transaction(function () use ($enrollment, $lesson): LessonProgress {
            // Upsert the progress row. updateOrCreate on the unique key keeps this
            // idempotent — marking an already-complete lesson just re-stamps it.
            $progress = LessonProgress::updateOrCreate(
                [
                    'enrollment_id' => $enrollment->id,
                    'lesson_id' => $lesson->id,
                ],
                [
                    'status' => 'completed',
                    'completed_at' => Carbon::now(),
                ],
            );

            // First activity on a live assignment starts the clock. Guarded so a
            // completed/failed/waived/requested enrolment is left exactly as it is.
            if ($enrollment->status === EnrollmentStatus::Assigned) {
                $enrollment->forceFill([
                    'status' => EnrollmentStatus::InProgress,
                    'started_at' => $enrollment->started_at ?? Carbon::now(),
                ])->save();
            }

            return $progress;
        });
    }
}
