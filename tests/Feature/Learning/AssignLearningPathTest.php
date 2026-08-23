<?php

declare(strict_types=1);

use App\Actions\Learning\AssignLearningPath;
use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\LearningPath;
use App\Models\PathEnrollment;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\CourseAssignedNotification;
use App\Notifications\PathAssignedNotification;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(Tenancy::class)->set($this->tenant->id);
    Notification::fake();
});

/** A path of $n published courses, and an employee with a login. */
function pathWithCourses(Tenant $tenant, int $n = 3): array
{
    return app(Tenancy::class)->runFor($tenant, function () use ($tenant, $n) {
        $path = LearningPath::factory()->create(['name' => 'Onboarding']);
        $courses = collect(range(1, $n))->map(fn ($i) => Course::factory()->create());
        $path->courses()->attach($courses->mapWithKeys(fn ($c, $i) => [$c->id => ['position' => $i + 1]])->all());

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        return ['path' => $path->fresh(), 'employee' => $employee, 'courses' => $courses];
    });
}

it('fans out one enrolment per course, tagged with the path', function () {
    ['path' => $path, 'employee' => $employee, 'courses' => $courses] = pathWithCourses($this->tenant, 3);

    app(Tenancy::class)->runFor($this->tenant, function () use ($path, $employee, $courses) {
        app(AssignLearningPath::class)->handle($path, $employee, 'Role onboarding');

        expect(Enrollment::where('employee_id', $employee->id)->count())->toBe(3)
            ->and(PathEnrollment::where('employee_id', $employee->id)->where('learning_path_id', $path->id)->count())->toBe(1);

        $enrollment = Enrollment::where('course_id', $courses->first()->id)->first();
        expect($enrollment->evidence['learning_path_id'])->toBe($path->id)
            ->and($enrollment->evidence['learning_path_name'])->toBe('Onboarding');
    });
});

it('sends one path notification, not one per course', function () {
    ['path' => $path, 'employee' => $employee] = pathWithCourses($this->tenant, 3);

    app(Tenancy::class)->runFor($this->tenant, fn () => app(AssignLearningPath::class)->handle($path, $employee, 'Onboarding'));

    Notification::assertSentToTimes($employee->user, PathAssignedNotification::class, 1);
    Notification::assertNotSentTo($employee->user, CourseAssignedNotification::class);
});

it('is idempotent — a re-assign adds nothing and does not re-notify', function () {
    ['path' => $path, 'employee' => $employee] = pathWithCourses($this->tenant, 3);

    app(Tenancy::class)->runFor($this->tenant, function () use ($path, $employee) {
        app(AssignLearningPath::class)->handle($path, $employee, 'First');
        app(AssignLearningPath::class)->handle($path, $employee, 'Again');

        expect(Enrollment::where('employee_id', $employee->id)->count())->toBe(3)
            ->and(PathEnrollment::where('employee_id', $employee->id)->count())->toBe(1);
    });

    Notification::assertSentToTimes($employee->user, PathAssignedNotification::class, 1);
});

it('computes path progress from completed courses', function () {
    ['path' => $path, 'employee' => $employee, 'courses' => $courses] = pathWithCourses($this->tenant, 4);

    $membership = app(Tenancy::class)->runFor($this->tenant, function () use ($path, $employee, $courses) {
        $m = app(AssignLearningPath::class)->handle($path, $employee, 'Onboarding');

        // Complete 2 of the 4 courses.
        Enrollment::where('employee_id', $employee->id)->whereIn('course_id', $courses->take(2)->pluck('id'))
            ->update(['status' => EnrollmentStatus::Completed->value]);

        return $m->fresh();
    });

    expect(app(Tenancy::class)->runFor($this->tenant, fn () => $membership->completionPercent()))->toBe(50);
});

it('scopes path assignment to the tenant', function () {
    ['path' => $path, 'employee' => $employee] = pathWithCourses($this->tenant, 2);
    app(Tenancy::class)->runFor($this->tenant, fn () => app(AssignLearningPath::class)->handle($path, $employee, 'Onboarding'));

    $other = Tenant::factory()->create();
    app(Tenancy::class)->runFor($other, fn () => expect(PathEnrollment::count())->toBe(0));
});
