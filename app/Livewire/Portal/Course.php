<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Actions\Learning\CompleteLesson;
use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\Lesson;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The course player: a learner works through an assigned course's lessons, one at
 * a time, marking each complete. It is the first half of the Sprint 5 journey —
 * lessons here, the quiz and certificate follow.
 *
 * Scoped hard to the signed-in learner's **own** enrolment. Tenant isolation is
 * automatic (BindCurrentTenant + the BelongsToTenant scope means a cross-tenant
 * id simply 404s on route binding); on top of that, mount() refuses an enrolment
 * that isn't this employee's, and a merely-`requested` one that was never granted.
 */
#[Layout('components.layouts.app')]
class Course extends Component
{
    public Enrollment $enrollment;

    /** Which lesson is open in the reader; null shows the course outline. */
    public ?string $lessonId = null;

    public function mount(Enrollment $enrollment): void
    {
        $employee = auth()->user()?->employee;

        // Own-enrolment only. 404 (not 403) so we never confirm the existence of
        // an enrolment belonging to someone else.
        abort_unless(
            $employee !== null && $enrollment->employee_id === $employee->id,
            404,
        );

        // A pending request carries no learning obligation and isn't takeable
        // until an approver turns it into an assignment.
        abort_if($enrollment->status === EnrollmentStatus::Requested, 404);

        $this->enrollment = $enrollment;
    }

    /** Open a lesson in the reader (only if it belongs to this course). */
    public function openLesson(string $lessonId): void
    {
        abort_unless($this->courseLessons()->contains('id', $lessonId), 404);

        $this->lessonId = $lessonId;
    }

    public function backToOutline(): void
    {
        $this->lessonId = null;
    }

    /**
     * Mark the given lesson complete, then advance the reader to the next
     * unfinished lesson (or back to the outline when the course is done). The
     * write and the assigned→in_progress transition live in {@see CompleteLesson}.
     */
    public function complete(string $lessonId, CompleteLesson $completeLesson): void
    {
        $lesson = $this->courseLessons()->firstWhere('id', $lessonId);
        abort_if($lesson === null, 404);

        $completeLesson->handle($this->enrollment, $lesson);

        // Refresh so the outline, progress bar and status pill reflect the write.
        $this->enrollment->refresh()->load('lessonProgress');

        $this->lessonId = $this->nextIncompleteLessonId($lessonId);
    }

    public function render(): View
    {
        $this->enrollment->loadMissing([
            'course' => fn ($q) => $q->withCount('lessons'),
            'course.lessons',
            'course.quiz',
            'lessonProgress',
        ]);

        $lessons = $this->courseLessons();
        $completedIds = $this->enrollment->completedLessonIds();

        return view('livewire.portal.course', [
            'course' => $this->enrollment->course,
            'lessons' => $lessons,
            'completedIds' => $completedIds,
            'currentLesson' => $this->lessonId !== null
                ? $lessons->firstWhere('id', $this->lessonId)
                : null,
            'percent' => $this->enrollment->completionPercent(),
            'allLessonsComplete' => $lessons->isNotEmpty()
                && $completedIds->count() >= $lessons->count(),
        ]);
    }

    /**
     * The course's lessons, loaded once. Ordered by position (the relation
     * already sorts), so "next lesson" is just the following row.
     *
     * @return Collection<int, Lesson>
     */
    private function courseLessons(): Collection
    {
        $this->enrollment->loadMissing('course.lessons');

        // An enrolment always has a course (non-null FK), so the relation resolves.
        return $this->enrollment->course->lessons;
    }

    /** The next not-yet-completed lesson after $afterId, or null if none remain. */
    private function nextIncompleteLessonId(string $afterId): ?string
    {
        $lessons = $this->courseLessons();
        $completed = $this->enrollment->completedLessonIds();

        // Walk from just after the one we finished, wrapping to the start, so a
        // learner who completes lessons out of order still lands on real work.
        $ordered = $lessons->pluck('id');
        $startIndex = (int) $ordered->search($afterId) + 1;

        foreach ($ordered->slice($startIndex)->concat($ordered->slice(0, $startIndex)) as $id) {
            if (! $completed->contains($id)) {
                return $id;
            }
        }

        return null; // everything is done — fall back to the outline
    }
}
