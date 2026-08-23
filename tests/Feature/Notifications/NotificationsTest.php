<?php

declare(strict_types=1);

use App\Actions\Assignment\AssignCourse;
use App\Actions\Learning\CompleteCourse;
use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AccessRequestedNotification;
use App\Notifications\CourseAssignedNotification;
use App\Notifications\CourseCompletedNotification;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(Tenancy::class)->set($this->tenant->id);
    Notification::fake();
});

/** An employee with a login (so they can be notified). */
function employeeWithLogin(Tenant $tenant, array $attrs = []): Employee
{
    return app(Tenancy::class)->runFor($tenant, function () use ($tenant, $attrs) {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        return Employee::factory()->create($attrs + ['user_id' => $user->id]);
    });
}

it('notifies the learner when a course is assigned', function () {
    $employee = employeeWithLogin($this->tenant);

    app(Tenancy::class)->runFor($this->tenant, function () use ($employee) {
        $course = Course::factory()->create(['title' => 'Consultative Selling']);
        app(AssignCourse::class)->handle($employee, $course, 'Role development', dueAt: now()->addDays(10));
    });

    Notification::assertSentTo($employee->user, CourseAssignedNotification::class);
});

it('does not re-notify on an idempotent re-assign', function () {
    $employee = employeeWithLogin($this->tenant);

    app(Tenancy::class)->runFor($this->tenant, function () use ($employee) {
        $course = Course::factory()->create();
        app(AssignCourse::class)->handle($employee, $course, 'First time');
        app(AssignCourse::class)->handle($employee, $course, 'Again (no transition)');
    });

    Notification::assertSentToTimes($employee->user, CourseAssignedNotification::class, 1);
});

it('notifies the manager when a learner requests access', function () {
    $manager = employeeWithLogin($this->tenant);
    $learner = employeeWithLogin($this->tenant, ['manager_id' => $manager->id]);

    app(Tenancy::class)->runFor($this->tenant, function () use ($learner) {
        $course = Course::factory()->create(['title' => 'AML']);
        app(AssignCourse::class)->request($learner, $course, 'Relevant to my role');
    });

    Notification::assertSentTo($manager->user, AccessRequestedNotification::class);
    // The learner is not told they were "assigned" — it's only a request.
    Notification::assertNotSentTo($learner->user, CourseAssignedNotification::class);
});

it('notifies the learner when their request is approved', function () {
    $employee = employeeWithLogin($this->tenant);

    app(Tenancy::class)->runFor($this->tenant, function () use ($employee) {
        $course = Course::factory()->create();
        // A pending request, then approval (requested → assigned).
        Enrollment::factory()->for($employee)->for($course)->create(['status' => EnrollmentStatus::Requested]);
        app(AssignCourse::class)->handle($employee, $course, 'Approved — go ahead');
    });

    Notification::assertSentTo($employee->user, CourseAssignedNotification::class);
});

it('notifies the learner when they complete a course', function () {
    $employee = employeeWithLogin($this->tenant);

    app(Tenancy::class)->runFor($this->tenant, function () use ($employee) {
        $course = Course::factory()->create(['title' => 'Consultative Selling']);
        $enrollment = Enrollment::factory()->for($employee)->for($course)->create(['status' => EnrollmentStatus::InProgress]);
        app(CompleteCourse::class)->handle($enrollment);
    });

    Notification::assertSentTo($employee->user, CourseCompletedNotification::class);
});

it('sends nothing when the learner has no login', function () {
    app(Tenancy::class)->runFor($this->tenant, function () {
        $employee = Employee::factory()->create(['user_id' => null]);
        $course = Course::factory()->create();
        app(AssignCourse::class)->handle($employee, $course, 'No login here');
    });

    Notification::assertNothingSent();
});
