<?php

declare(strict_types=1);

use App\Actions\Learning\CompleteLesson;
use App\Enums\CourseStatus;
use App\Enums\EnrollmentStatus;
use App\Livewire\Portal\Course as CoursePlayer;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
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
 * Build a published course with $lessonCount lessons and an assignment for the
 * beforeEach employee, all inside the tenant context.
 */
function enrolInCourse(Tenant $tenant, Employee $employee, int $lessonCount = 3, EnrollmentStatus $status = EnrollmentStatus::Assigned): Enrollment
{
    return app(Tenancy::class)->runFor($tenant, function () use ($employee, $lessonCount, $status) {
        $course = Course::factory()->create(['status' => CourseStatus::Published]);
        for ($i = 1; $i <= $lessonCount; $i++) {
            Lesson::factory()->create(['course_id' => $course->id, 'position' => $i]);
        }

        return Enrollment::factory()->create([
            'employee_id' => $employee->id,
            'course_id' => $course->id,
            'status' => $status,
        ]);
    });
}

it('requires authentication', function () {
    $enrollment = enrolInCourse($this->tenant, $this->employee);

    $this->get(route('portal.course', $enrollment))->assertRedirect('/login');
});

it('lets a learner open their own course and see its lessons', function () {
    $enrollment = enrolInCourse($this->tenant, $this->employee);
    $firstLesson = $enrollment->course->lessons->first();

    $this->actingAs($this->user);

    Livewire::test(CoursePlayer::class, ['enrollment' => $enrollment])
        ->assertOk()
        ->assertSee($firstLesson->title);
});

it('marks a lesson complete, writing one idempotent progress row and starting the course', function () {
    $enrollment = enrolInCourse($this->tenant, $this->employee);
    $lesson = $enrollment->course->lessons->first();

    $this->actingAs($this->user);

    $component = Livewire::test(CoursePlayer::class, ['enrollment' => $enrollment])
        ->call('complete', $lesson->id)
        ->call('complete', $lesson->id); // second call must not duplicate

    app(Tenancy::class)->runFor($this->tenant, function () use ($enrollment, $lesson) {
        expect(LessonProgress::where('enrollment_id', $enrollment->id)->where('lesson_id', $lesson->id)->count())->toBe(1)
            ->and($enrollment->fresh()->status)->toBe(EnrollmentStatus::InProgress)
            ->and($enrollment->fresh()->started_at)->not->toBeNull();
    });
});

it('reaches 100% and unlocks the assessment once every lesson is complete', function () {
    $enrollment = enrolInCourse($this->tenant, $this->employee, lessonCount: 2);
    // Give the course a quiz so the gate renders.
    app(Tenancy::class)->runFor($this->tenant, fn () => $enrollment->course->quiz()->create(['title' => 'Assessment']));

    $this->actingAs($this->user);

    $component = Livewire::test(CoursePlayer::class, ['enrollment' => $enrollment]);
    foreach ($enrollment->course->lessons as $lesson) {
        $component->call('complete', $lesson->id);
    }

    $component->assertSet('lessonId', null) // advanced past the last lesson → outline
        ->assertSee('Take assessment');

    expect($enrollment->fresh()->completionPercent())->toBe(100);
});

it('404s on another employee\'s enrolment', function () {
    $otherUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherEnrollment = app(Tenancy::class)->runFor($this->tenant, function () {
        $other = Employee::factory()->create();

        return enrolInCourse($this->tenant, $other);
    });

    $this->actingAs($this->user)
        ->get(route('portal.course', $otherEnrollment))
        ->assertNotFound();
});

it('404s on a still-requested enrolment (not yet a real assignment)', function () {
    $enrollment = enrolInCourse($this->tenant, $this->employee, status: EnrollmentStatus::Requested);

    $this->actingAs($this->user)
        ->get(route('portal.course', $enrollment))
        ->assertNotFound();
});

it('CompleteLesson never clobbers a terminal enrolment status', function () {
    $enrollment = enrolInCourse($this->tenant, $this->employee, status: EnrollmentStatus::Completed);
    $lesson = $enrollment->course->lessons->first();

    app(Tenancy::class)->runFor($this->tenant, function () use ($enrollment, $lesson) {
        app(CompleteLesson::class)->handle($enrollment, $lesson);

        // A completed course stays completed — re-reading a lesson doesn't reopen it.
        expect($enrollment->fresh()->status)->toBe(EnrollmentStatus::Completed);
    });
});
