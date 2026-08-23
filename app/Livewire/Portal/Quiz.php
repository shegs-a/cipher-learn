<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use App\Actions\Learning\QuizAttemptsExhausted;
use App\Actions\Learning\SubmitQuizAttempt;
use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\QuizAttempt;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The assessment: the learner answers the course quiz and gets a server-graded
 * pass/fail. The second half of the Sprint 5 journey (lessons → **quiz** →
 * certificate).
 *
 * Same hard scoping as the course player — the learner's own, non-`requested`
 * enrolment only. The quiz is gated behind completing every lesson; a learner who
 * hasn't is redirected back to the outline. Grading and the attempt ceiling live
 * entirely in {@see SubmitQuizAttempt} — this component never sees a correct key.
 */
#[Layout('components.layouts.app')]
class Quiz extends Component
{
    public Enrollment $enrollment;

    /**
     * questionId => submitted option key (single/boolean) or list of keys (multiple).
     *
     * @var array<string, string|list<string>>
     */
    public array $answers = [];

    /** True right after a submission, so the result panel shows instead of the form. */
    public bool $showResult = false;

    public function mount(Enrollment $enrollment): mixed
    {
        $employee = auth()->user()?->employee;

        abort_unless(
            $employee !== null && $enrollment->employee_id === $employee->id,
            404,
        );
        abort_if($enrollment->status === EnrollmentStatus::Requested, 404);

        $enrollment->loadMissing(['course.quiz', 'course.lessons', 'lessonProgress']);

        // No quiz, nothing to assess.
        abort_if($enrollment->course->quiz === null, 404);

        $this->enrollment = $enrollment;

        $isResolved = in_array($enrollment->status, [
            EnrollmentStatus::Completed, EnrollmentStatus::Failed,
        ], true);

        // Gate: every lesson must be complete first — but never bounce a learner
        // whose course is already resolved (they may be reviewing a pass/fail).
        if (! $isResolved && ! $this->allLessonsComplete()) {
            return $this->redirect(route('portal.course', $enrollment), navigate: true);
        }

        // Coming back to a resolved course lands straight on the result.
        $this->showResult = $isResolved;

        return null;
    }

    public function submit(SubmitQuizAttempt $submitQuizAttempt): void
    {
        $quiz = $this->enrollment->course->quiz;

        try {
            $submitQuizAttempt->handle($this->enrollment, $quiz, $this->answers);
        } catch (QuizAttemptsExhausted $e) {
            // The UI gates this; if we somehow got here, just re-render the
            // (now locked) state rather than erroring at the learner.
        }

        $this->enrollment->refresh();
        $this->showResult = true;
    }

    /** Discard the last answers and take another allowed attempt. */
    public function retry(): void
    {
        $this->answers = [];
        $this->showResult = false;
    }

    public function render(): View
    {
        $quiz = $this->enrollment->course->quiz;
        $quiz->loadMissing('questions');

        $attemptsUsed = $this->enrollment->quizAttempts()->where('quiz_id', $quiz->id)->count();
        $max = $quiz->effectiveMaxAttempts();

        return view('livewire.portal.quiz', [
            'course' => $this->enrollment->course,
            'quiz' => $quiz,
            // Only what the learner may see — prompt, type, options. Never the keys.
            'questions' => $quiz->questions,
            'passMark' => $quiz->effectivePassMark(),
            'attemptsUsed' => $attemptsUsed,
            'attemptsRemaining' => max(0, $max - $attemptsUsed),
            'lastAttempt' => $this->latestAttempt(),
            'passed' => $this->enrollment->status === EnrollmentStatus::Completed,
            'certificate' => $this->enrollment->certificate,
        ]);
    }

    private function latestAttempt(): ?QuizAttempt
    {
        return $this->enrollment->quizAttempts()
            ->where('quiz_id', $this->enrollment->course->quiz->id)
            ->latest('submitted_at')
            ->first();
    }

    private function allLessonsComplete(): bool
    {
        $lessons = $this->enrollment->course->lessons;

        return $lessons->isNotEmpty()
            && $this->enrollment->completedLessonIds()->count() >= $lessons->count();
    }
}
