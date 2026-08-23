<?php

declare(strict_types=1);

use App\Actions\Learning\QuizAttemptsExhausted;
use App\Actions\Learning\SubmitQuizAttempt;
use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Livewire\Portal\Quiz as QuizComponent;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(Tenancy::class)->set($this->tenant->id);

    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    app(Tenancy::class)->runFor($this->tenant, function () {
        $this->employee = Employee::factory()->create(['user_id' => $this->user->id]);
    });
});

/**
 * A published course with one lesson (marked complete), a quiz with two
 * single-choice questions (correct key 'a', 2 points each) and an assignment.
 *
 * @return array{enrollment: Enrollment, quiz: Quiz}
 */
function makeAssessableCourse(Tenant $tenant, Employee $employee, ?int $maxAttempts = null): array
{
    return app(Tenancy::class)->runFor($tenant, function () use ($employee, $maxAttempts) {
        $course = Course::factory()->create([
            'status' => CourseStatus::Published,
            'pass_mark' => 70,
            'max_attempts' => $maxAttempts,
        ]);
        $lesson = Lesson::factory()->create(['course_id' => $course->id, 'position' => 1]);

        $enrollment = Enrollment::factory()->create([
            'employee_id' => $employee->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::InProgress,
        ]);
        // Complete the single lesson so the quiz gate is open.
        LessonProgress::create([
            'enrollment_id' => $enrollment->id,
            'lesson_id' => $lesson->id,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $quiz = Quiz::create(['course_id' => $course->id, 'title' => 'Assessment']);
        foreach ([1, 2] as $pos) {
            Question::create([
                'quiz_id' => $quiz->id,
                'prompt' => "Question {$pos}",
                'type' => 'single',
                'options' => [['key' => 'a', 'label' => 'Right'], ['key' => 'b', 'label' => 'Wrong']],
                'correct_keys' => ['a'],
                'points' => 2,
                'position' => $pos,
            ]);
        }

        return ['enrollment' => $enrollment->fresh(), 'quiz' => $quiz->fresh()];
    });
}

it('grades a passing submission and completes the course', function () {
    ['enrollment' => $enrollment, 'quiz' => $quiz] = makeAssessableCourse($this->tenant, $this->employee);
    $qids = $quiz->questions->pluck('id');

    app(Tenancy::class)->runFor($this->tenant, function () use ($enrollment, $quiz, $qids) {
        $attempt = app(SubmitQuizAttempt::class)->handle($enrollment, $quiz, [
            $qids[0] => 'a',
            $qids[1] => 'a',
        ]);

        expect($attempt->score)->toBe(100)
            ->and($attempt->passed)->toBeTrue()
            ->and($enrollment->fresh()->status)->toBe(EnrollmentStatus::Completed)
            ->and($enrollment->fresh()->completed_at)->not->toBeNull()
            // The stored answers must never contain the correct keys column.
            ->and(array_keys($attempt->answers))->toEqualCanonicalizing($qids->all());
    });
});

it('grades a failing submission below the pass mark', function () {
    ['enrollment' => $enrollment, 'quiz' => $quiz] = makeAssessableCourse($this->tenant, $this->employee);
    $qids = $quiz->questions->pluck('id');

    app(Tenancy::class)->runFor($this->tenant, function () use ($enrollment, $quiz, $qids) {
        $attempt = app(SubmitQuizAttempt::class)->handle($enrollment, $quiz, [
            $qids[0] => 'a',
            $qids[1] => 'b', // wrong → 50%
        ]);

        expect($attempt->score)->toBe(50)
            ->and($attempt->passed)->toBeFalse()
            // Attempts remain (default 3), so the course stays in progress.
            ->and($enrollment->fresh()->status)->toBe(EnrollmentStatus::InProgress);
    });
});

it('never persists the correct keys, only the learner answers', function () {
    ['enrollment' => $enrollment, 'quiz' => $quiz] = makeAssessableCourse($this->tenant, $this->employee);
    $qids = $quiz->questions->pluck('id');

    app(Tenancy::class)->runFor($this->tenant, function () use ($enrollment, $quiz, $qids) {
        $attempt = app(SubmitQuizAttempt::class)->handle($enrollment, $quiz, [$qids[0] => 'a', $qids[1] => 'b']);

        // The raw stored JSON holds the learner's keys, and nothing resembling
        // a `correct_keys` structure.
        $raw = json_encode($attempt->fresh()->answers);
        expect($raw)->not->toContain('correct_keys');
        // Serialising a question must not leak the answer either.
        expect(array_keys($quiz->questions->first()->toArray()))->not->toContain('correct_keys');
    });
});

it('marks the course failed when the last attempt is exhausted', function () {
    ['enrollment' => $enrollment, 'quiz' => $quiz] = makeAssessableCourse($this->tenant, $this->employee, maxAttempts: 2);
    $qids = $quiz->questions->pluck('id');
    $wrong = [$qids[0] => 'b', $qids[1] => 'b'];

    app(Tenancy::class)->runFor($this->tenant, function () use ($enrollment, $quiz, $wrong) {
        app(SubmitQuizAttempt::class)->handle($enrollment, $quiz, $wrong); // attempt 1
        expect($enrollment->fresh()->status)->toBe(EnrollmentStatus::InProgress);

        app(SubmitQuizAttempt::class)->handle($enrollment, $quiz, $wrong); // attempt 2 (last)
        expect($enrollment->fresh()->status)->toBe(EnrollmentStatus::Failed);
    });
});

it('refuses a submission once attempts are exhausted', function () {
    ['enrollment' => $enrollment, 'quiz' => $quiz] = makeAssessableCourse($this->tenant, $this->employee, maxAttempts: 1);
    $qids = $quiz->questions->pluck('id');

    app(Tenancy::class)->runFor($this->tenant, function () use ($enrollment, $quiz, $qids) {
        app(SubmitQuizAttempt::class)->handle($enrollment, $quiz, [$qids[0] => 'b', $qids[1] => 'b']);

        expect(fn () => app(SubmitQuizAttempt::class)->handle($enrollment, $quiz, [$qids[0] => 'a', $qids[1] => 'a']))
            ->toThrow(QuizAttemptsExhausted::class);
    });
});

it('lets the learner submit the quiz through the component and see a pass', function () {
    ['enrollment' => $enrollment, 'quiz' => $quiz] = makeAssessableCourse($this->tenant, $this->employee);
    $qids = $quiz->questions->pluck('id');

    $this->actingAs($this->user);

    Livewire::test(QuizComponent::class, ['enrollment' => $enrollment])
        ->set("answers.{$qids[0]}", 'a')
        ->set("answers.{$qids[1]}", 'a')
        ->call('submit')
        ->assertSee('Passed')
        ->assertSee('100%');

    expect($enrollment->fresh()->status)->toBe(EnrollmentStatus::Completed);
});

it('redirects to the course when lessons are not all complete', function () {
    $enrollment = app(Tenancy::class)->runFor($this->tenant, function () {
        $course = Course::factory()->create(['status' => CourseStatus::Published]);
        Lesson::factory()->create(['course_id' => $course->id, 'position' => 1]); // not completed
        Quiz::create(['course_id' => $course->id, 'title' => 'Assessment']);

        return Enrollment::factory()->create([
            'employee_id' => $this->employee->id,
            'course_id' => $course->id,
            'status' => EnrollmentStatus::InProgress,
        ]);
    });

    $this->actingAs($this->user);

    Livewire::test(QuizComponent::class, ['enrollment' => $enrollment])
        ->assertRedirect(route('portal.course', $enrollment));
});

it('404s on another employee\'s assessment', function () {
    $otherEnrollment = app(Tenancy::class)->runFor($this->tenant, function () {
        $other = Employee::factory()->create();

        return makeAssessableCourse($this->tenant, $other)['enrollment'];
    });

    $this->actingAs($this->user)
        ->get(route('portal.course.quiz', $otherEnrollment))
        ->assertNotFound();
});
