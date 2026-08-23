<?php

declare(strict_types=1);

use App\Actions\Assignment\AssignCourse;
use App\Enums\EnrollmentSource;
use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(Tenancy::class)->set($this->tenant->id);
    $this->employee = Employee::factory()->create();
    $this->course = Course::factory()->create();
    $this->assigner = User::factory()->create(['tenant_id' => $this->tenant->id]);
});

it('creates an assignment carrying the reason and the assigner', function () {
    app(Tenancy::class)->runFor($this->tenant, function () {
        $enrollment = app(AssignCourse::class)->handle(
            employee: $this->employee,
            course: $this->course,
            rationale: 'Collections KPI at 61% of target — below threshold.',
            assignedBy: $this->assigner,
            dueAt: now()->addDays(30),
        );

        expect($enrollment->status)->toBe(EnrollmentStatus::Assigned)
            ->and($enrollment->source)->toBe(EnrollmentSource::Manual)
            ->and($enrollment->rationale)->toBe('Collections KPI at 61% of target — below threshold.')
            ->and($enrollment->assigned_by_user_id)->toBe($this->assigner->id)
            ->and($enrollment->tenant_id)->toBe($this->tenant->id);
    });
});

it('is idempotent — re-assigning the same course updates rather than duplicating', function () {
    app(Tenancy::class)->runFor($this->tenant, function () {
        app(AssignCourse::class)->handle($this->employee, $this->course, 'First reason');
        app(AssignCourse::class)->handle($this->employee, $this->course, 'Updated reason');

        expect(Enrollment::count())->toBe(1)
            ->and(Enrollment::first()->rationale)->toBe('Updated reason');
    });
});

it('turns a learner request into an assignment on approval', function () {
    app(Tenancy::class)->runFor($this->tenant, function () {
        // Learner requests...
        $request = app(AssignCourse::class)->request($this->employee, $this->course, 'I need this for my role.');
        expect($request->status)->toBe(EnrollmentStatus::Requested)
            ->and($request->source)->toBe(EnrollmentSource::SelfEnrolled);

        // ...a manager approves by assigning — same row, now assigned.
        $approved = app(AssignCourse::class)->handle(
            $this->employee, $this->course,
            rationale: 'Approved — aligns with development plan.',
            assignedBy: $this->assigner,
        );

        expect($approved->id)->toBe($request->id)
            ->and(Enrollment::count())->toBe(1)
            ->and($approved->status)->toBe(EnrollmentStatus::Assigned)
            ->and($approved->rationale)->toBe('Approved — aligns with development plan.');
    });
});

it('never clobbers a completed enrolment', function () {
    app(Tenancy::class)->runFor($this->tenant, function () {
        Enrollment::factory()->create([
            'employee_id' => $this->employee->id,
            'course_id' => $this->course->id,
            'status' => EnrollmentStatus::Completed,
            'rationale' => 'Original completion.',
        ]);

        $result = app(AssignCourse::class)->handle($this->employee, $this->course, 'Trying to reassign');

        expect($result->status)->toBe(EnrollmentStatus::Completed)
            ->and($result->rationale)->toBe('Original completion.'); // untouched
    });
});
