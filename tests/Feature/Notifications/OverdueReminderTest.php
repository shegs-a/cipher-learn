<?php

declare(strict_types=1);

use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\OverdueReminderNotification;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    Notification::fake();
});

/** An overdue open enrolment for a learner with a login. */
function overdueEnrolment(Tenant $tenant): Enrollment
{
    return app(Tenancy::class)->runFor($tenant, function () use ($tenant) {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        return Enrollment::factory()->for($employee)->for(Course::factory()->create())->create([
            'status' => EnrollmentStatus::Assigned,
            'due_at' => now()->subDays(5),
            'reminded_at' => null,
        ]);
    });
}

it('reminds a learner about an overdue course and stamps reminded_at', function () {
    $enrollment = overdueEnrolment($this->tenant);

    // No tenant bound — the command sweeps across tenants like the scheduler.
    $this->artisan('notifications:overdue')->assertSuccessful();

    Notification::assertSentTo($enrollment->employee->user, OverdueReminderNotification::class);
    expect($enrollment->fresh()->reminded_at)->not->toBeNull();
});

it('does not re-remind within the cadence window', function () {
    overdueEnrolment($this->tenant);

    $this->artisan('notifications:overdue')->assertSuccessful(); // first: sends
    $this->artisan('notifications:overdue')->assertSuccessful(); // second: within window, skips

    Notification::assertSentTimes(OverdueReminderNotification::class, 1);
});

it('does not remind about a course that is not overdue', function () {
    app(Tenancy::class)->runFor($this->tenant, function () use (&$user) {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $employee = Employee::factory()->create(['user_id' => $user->id]);
        Enrollment::factory()->for($employee)->for(Course::factory()->create())->create([
            'status' => EnrollmentStatus::InProgress,
            'due_at' => now()->addDays(5), // future
        ]);
    });

    $this->artisan('notifications:overdue')->assertSuccessful();

    Notification::assertNothingSent();
});

it('does not remind about a completed course', function () {
    app(Tenancy::class)->runFor($this->tenant, function () {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $employee = Employee::factory()->create(['user_id' => $user->id]);
        Enrollment::factory()->for($employee)->for(Course::factory()->create())->create([
            'status' => EnrollmentStatus::Completed,
            'due_at' => now()->subDays(5),
        ]);
    });

    $this->artisan('notifications:overdue')->assertSuccessful();

    Notification::assertNothingSent();
});
