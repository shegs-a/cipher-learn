<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\EnrollmentStatus;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\QuizAttempt;
use App\Models\Tenant;
use App\Support\DashboardMetrics;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(Tenancy::class)->set($this->tenant->id);
    $this->metrics = app(DashboardMetrics::class);
});

/** Run a closure inside the beforeEach tenant. */
function inTenant(Tenant $tenant, Closure $fn): mixed
{
    return app(Tenancy::class)->runFor($tenant, $fn);
}

it('counts active headcount and leavers', function () {
    inTenant($this->tenant, function () {
        Employee::factory()->count(3)->create(['status' => EmployeeStatus::Active]);
        Employee::factory()->count(2)->create(['status' => EmployeeStatus::Exited]);
    });

    expect($this->metrics->activeHeadcount())->toBe(3)
        ->and($this->metrics->leavers())->toBe(2);
});

it('counts active and overdue enrolments', function () {
    inTenant($this->tenant, function () {
        $emp = Employee::factory()->create();
        $course = Course::factory()->create();
        Enrollment::factory()->for($emp)->for($course)->create(['status' => EnrollmentStatus::Assigned, 'due_at' => now()->addDays(5)]);
        Enrollment::factory()->for($emp)->for(Course::factory()->create())->create(['status' => EnrollmentStatus::InProgress, 'due_at' => now()->subDay()]); // overdue
        Enrollment::factory()->for($emp)->for(Course::factory()->create())->create(['status' => EnrollmentStatus::Completed, 'due_at' => now()->subDay()]); // not open → not overdue
    });

    expect($this->metrics->activeEnrolments())->toBe(2)
        ->and($this->metrics->overdue())->toBe(1);
});

it('computes completion rate over counted enrolments only', function () {
    inTenant($this->tenant, function () {
        $emp = Employee::factory()->create();
        // 2 completed, 1 in progress, 1 failed → counted = 4, completed = 2 → 50%.
        Enrollment::factory()->for($emp)->for(Course::factory()->create())->create(['status' => EnrollmentStatus::Completed]);
        Enrollment::factory()->for($emp)->for(Course::factory()->create())->create(['status' => EnrollmentStatus::Completed]);
        Enrollment::factory()->for($emp)->for(Course::factory()->create())->create(['status' => EnrollmentStatus::InProgress]);
        Enrollment::factory()->for($emp)->for(Course::factory()->create())->create(['status' => EnrollmentStatus::Failed]);
        // Requested + cancelled must NOT dilute the denominator.
        Enrollment::factory()->for($emp)->for(Course::factory()->create())->create(['status' => EnrollmentStatus::Requested]);
        Enrollment::factory()->for($emp)->for(Course::factory()->create())->create(['status' => EnrollmentStatus::Cancelled]);
    });

    expect($this->metrics->completionRate())->toBe(50);
});

it('computes pass rate over graded attempts', function () {
    inTenant($this->tenant, function () {
        $course = Course::factory()->create();
        $quiz = $course->quiz()->create(['title' => 'Assessment']);
        $enrollment = Enrollment::factory()->for(Employee::factory())->for($course)->create();
        $fk = ['enrollment_id' => $enrollment->id, 'quiz_id' => $quiz->id, 'employee_id' => $enrollment->employee_id, 'submitted_at' => now()];
        QuizAttempt::factory()->count(3)->create($fk + ['passed' => true]);
        QuizAttempt::factory()->count(1)->create($fk + ['passed' => false]);
    });

    expect($this->metrics->passRate())->toBe(75);
});

it('counts certificates and recert-due within the window', function () {
    inTenant($this->tenant, function () {
        $emp = Employee::factory()->create();
        // A helper: one enrolment (distinct course) → one certificate with $expiry.
        $issue = function (?Carbon $expiry) use ($emp) {
            $course = Course::factory()->create();
            $enrollment = Enrollment::factory()->for($emp)->for($course)->create(['status' => EnrollmentStatus::Completed]);

            return Certificate::factory()->create([
                'employee_id' => $emp->id,
                'course_id' => $course->id,
                'enrollment_id' => $enrollment->id,
                'expires_at' => $expiry,
            ]);
        };

        $issue(now()->addDays(30));  // due soon
        $issue(now()->addDays(200)); // not soon
        $issue(null);                // never expires
    });

    expect($this->metrics->certificatesIssued())->toBe(3)
        ->and($this->metrics->recertDueSoon(60))->toBe(1);
});

it('buckets completions by week', function () {
    inTenant($this->tenant, function () {
        $emp = Employee::factory()->create();
        Enrollment::factory()->for($emp)->for(Course::factory()->create())->create(['status' => EnrollmentStatus::Completed, 'completed_at' => now()]);
        Enrollment::factory()->for($emp)->for(Course::factory()->create())->create(['status' => EnrollmentStatus::Completed, 'completed_at' => now()->subWeeks(2)]);
    });

    $byWeek = $this->metrics->completionsByWeek(8);

    expect($byWeek)->toHaveCount(8)
        ->and(array_sum($byWeek))->toBe(2);
});

it('ranks course coverage by enrolments with completion counts', function () {
    inTenant($this->tenant, function () {
        $popular = Course::factory()->create(['title' => 'Popular']);
        $quiet = Course::factory()->create(['title' => 'Quiet']);
        $emp = Employee::factory()->create();
        Enrollment::factory()->for($emp)->for($popular)->create(['status' => EnrollmentStatus::Completed]);
        Enrollment::factory()->for(Employee::factory())->for($popular)->create(['status' => EnrollmentStatus::InProgress]);
        Enrollment::factory()->for($emp)->for($quiet)->create(['status' => EnrollmentStatus::Assigned]);
    });

    $coverage = $this->metrics->courseCoverage();

    expect($coverage->first()['title'])->toBe('Popular')
        ->and($coverage->first()['enrolments'])->toBe(2)
        ->and($coverage->first()['completed'])->toBe(1);
});

it('scopes every metric to the current tenant', function () {
    // Seed a whole other tenant's world.
    $other = Tenant::factory()->create();
    inTenant($other, function () {
        Employee::factory()->count(5)->create(['status' => EmployeeStatus::Active]);
        $e = Enrollment::factory()->for(Employee::factory())->for(Course::factory())->create(['status' => EnrollmentStatus::Completed]);
        Certificate::factory()->create(['employee_id' => $e->employee_id, 'course_id' => $e->course_id, 'enrollment_id' => $e->id]);
    });

    // The beforeEach tenant is empty → nothing from `$other` leaks in.
    expect($this->metrics->activeHeadcount())->toBe(0)
        ->and($this->metrics->completionRate())->toBe(0)
        ->and($this->metrics->certificatesIssued())->toBe(0);
});
