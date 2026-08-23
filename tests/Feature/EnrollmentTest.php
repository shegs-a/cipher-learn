<?php

declare(strict_types=1);

use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(Tenancy::class)->set($this->tenant->id);
    $this->employee = Employee::factory()->create();
    $this->course = Course::factory()->create();
});

it('prevents a duplicate enrolment within the same cycle', function () {
    Enrollment::factory()->create([
        'employee_id' => $this->employee->id,
        'course_id' => $this->course->id,
        'cycle' => 'initial',
    ]);

    // The unique (tenant_id, employee_id, course_id, cycle) index — not app
    // logic — rejects a second enrolment for the SAME cycle. This is also what
    // makes the Sprint 3 assignment sweep idempotent when re-run for a cycle.
    expect(fn () => Enrollment::factory()->create([
        'employee_id' => $this->employee->id,
        'course_id' => $this->course->id,
        'cycle' => 'initial',
    ]))->toThrow(QueryException::class);
});

it('allows re-enrolment in the same course for a new recertification cycle', function () {
    // The original completion is kept (never deleted)...
    $first = Enrollment::factory()->create([
        'employee_id' => $this->employee->id,
        'course_id' => $this->course->id,
        'cycle' => 'initial',
        'status' => EnrollmentStatus::Completed,
    ]);

    // ...and Sprint 5's recert job can create a fresh enrolment for the next
    // cycle without colliding with, or destroying, the prior record.
    $second = Enrollment::factory()->create([
        'employee_id' => $this->employee->id,
        'course_id' => $this->course->id,
        'cycle' => '2027-H1',
    ]);

    expect($first->id)->not->toBe($second->id)
        ->and(Enrollment::where('employee_id', $this->employee->id)
            ->where('course_id', $this->course->id)->count())->toBe(2);
});

it('requires a rationale and evidence on every enrolment', function () {
    expect(fn () => Enrollment::create([
        'employee_id' => $this->employee->id,
        'course_id' => $this->course->id,
        'source' => 'manual',
        'status' => 'assigned',
        // rationale + evidence deliberately omitted
    ]))->toThrow(QueryException::class);
});

it('cancels rather than deletes — status change preserves the record', function () {
    $enrollment = Enrollment::factory()->create([
        'employee_id' => $this->employee->id,
        'course_id' => $this->course->id,
    ]);

    $enrollment->update(['status' => EnrollmentStatus::Cancelled]);

    expect(Enrollment::whereKey($enrollment->id)->exists())->toBeTrue()
        ->and($enrollment->fresh()->status)->toBe(EnrollmentStatus::Cancelled);
});
