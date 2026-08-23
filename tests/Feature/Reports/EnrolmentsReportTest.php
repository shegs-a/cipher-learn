<?php

declare(strict_types=1);

use App\Enums\EnrollmentStatus;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Tenant;
use App\Support\Reports\CsvExporter;
use App\Support\Reports\EnrolmentsReport;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(Tenancy::class)->set($this->tenant->id);
    $this->report = new EnrolmentsReport;
});

function seedEnrolment(Tenant $tenant, array $employeeAttrs, array $courseAttrs, array $enrollmentAttrs = []): Enrollment
{
    return app(Tenancy::class)->runFor($tenant, function () use ($employeeAttrs, $courseAttrs, $enrollmentAttrs) {
        $employee = Employee::factory()->create($employeeAttrs);
        $course = Course::factory()->create($courseAttrs);

        return Enrollment::factory()->for($employee)->for($course)->create($enrollmentAttrs);
    });
}

it('returns all enrolments unfiltered, tenant-scoped', function () {
    seedEnrolment($this->tenant, ['department' => 'Sales'], ['title' => 'Consultative Selling']);
    seedEnrolment($this->tenant, ['department' => 'Finance'], ['title' => 'AML']);

    // Another tenant's enrolment must never appear.
    $other = Tenant::factory()->create();
    seedEnrolment($other, ['department' => 'Sales'], ['title' => 'Secret Course']);

    $rows = app(Tenancy::class)->runFor($this->tenant, fn () => $this->report->query([])->get());

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('course.title')->all())->not->toContain('Secret Course');
});

it('filters by course, department and status', function () {
    $target = seedEnrolment($this->tenant, ['department' => 'Sales'], ['title' => 'Consultative Selling'], ['status' => EnrollmentStatus::Completed]);
    seedEnrolment($this->tenant, ['department' => 'Finance'], ['title' => 'AML'], ['status' => EnrollmentStatus::Assigned]);

    app(Tenancy::class)->runFor($this->tenant, function () use ($target) {
        expect($this->report->query(['course_id' => $target->course_id])->count())->toBe(1)
            ->and($this->report->query(['department' => 'Sales'])->count())->toBe(1)
            ->and($this->report->query(['department' => 'Finance'])->count())->toBe(1)
            ->and($this->report->query(['status' => EnrollmentStatus::Completed->value])->count())->toBe(1)
            ->and($this->report->query(['status' => EnrollmentStatus::Failed->value])->count())->toBe(0);
    });
});

it('filters by assigned date range', function () {
    $recent = seedEnrolment($this->tenant, ['department' => 'Sales'], ['title' => 'A']);
    $old = seedEnrolment($this->tenant, ['department' => 'Sales'], ['title' => 'B']);
    app(Tenancy::class)->runFor($this->tenant, fn () => $old->forceFill(['created_at' => now()->subMonths(2)])->save());

    app(Tenancy::class)->runFor($this->tenant, function () {
        $rows = $this->report->query(['from' => now()->subWeek()->format('Y-m-d')])->get();
        expect($rows)->toHaveCount(1)
            ->and($rows->first()->course->title)->toBe('A');
    });
});

it('maps a row to its column values', function () {
    $enrollment = seedEnrolment($this->tenant, ['first_name' => 'Ada', 'last_name' => 'Obi', 'department' => 'Sales'], ['title' => 'Consultative Selling'], ['status' => EnrollmentStatus::InProgress]);

    $row = app(Tenancy::class)->runFor($this->tenant, fn () => $this->report->row($enrollment->fresh(['employee', 'course', 'assignedBy'])));

    expect($row['learner'])->toBe('Ada Obi')
        ->and($row['department'])->toBe('Sales')
        ->and($row['course'])->toBe('Consultative Selling')
        ->and($row['status'])->toBe('In progress');
});

it('exports CSV with a header row and one line per record', function () {
    seedEnrolment($this->tenant, ['first_name' => 'Ada', 'last_name' => 'Obi', 'department' => 'Sales'], ['title' => 'Consultative Selling']);

    $csv = app(Tenancy::class)->runFor($this->tenant, fn () => app(CsvExporter::class)->toString($this->report, []));
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    expect($lines)->toHaveCount(2)                    // header + 1 row
        ->and($lines[0])->toContain('Learner,Department,Course')
        ->and($lines[1])->toContain('Ada Obi')
        ->and($lines[1])->toContain('Consultative Selling');
});

it('escapes commas and quotes in CSV fields', function () {
    seedEnrolment($this->tenant, ['first_name' => 'Ada', 'last_name' => 'Obi', 'department' => 'Sales'], ['title' => 'Selling, Advanced "Pro"']);

    $csv = app(Tenancy::class)->runFor($this->tenant, fn () => app(CsvExporter::class)->toString($this->report, []));

    // fputcsv wraps a field with a comma/quote in quotes and doubles inner quotes.
    expect($csv)->toContain('"Selling, Advanced ""Pro"""');
});
