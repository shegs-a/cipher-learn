<?php

declare(strict_types=1);

use App\Actions\Assignment\AssignCourseOrgWide;
use App\Enums\EmployeeStatus;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(Tenancy::class)->set($this->tenant->id);
});

it('assigns a course to a single department only', function () {
    app(Tenancy::class)->runFor($this->tenant, function () {
        $course = Course::factory()->create();
        $finance = Employee::factory()->count(3)->create(['department' => 'Finance']);
        Employee::factory()->count(2)->create(['department' => 'Sales']);

        $result = app(AssignCourseOrgWide::class)->handle($course, 'AML refresher', department: 'Finance');

        expect($result->assigned())->toBe(3)
            ->and(Enrollment::count())->toBe(3)
            ->and(Enrollment::pluck('employee_id')->sort()->values()->all())
            ->toBe($finance->pluck('id')->sort()->values()->all());
    });
});

it('assigns org-wide to every active employee', function () {
    app(Tenancy::class)->runFor($this->tenant, function () {
        $course = Course::factory()->create();
        Employee::factory()->count(4)->create(['status' => EmployeeStatus::Active]);
        Employee::factory()->create(['status' => EmployeeStatus::Exited]); // skipped

        $result = app(AssignCourseOrgWide::class)->handle($course, 'Mandatory for all staff');

        expect($result->assigned())->toBe(4)
            ->and(Enrollment::count())->toBe(4);
    });
});

it('is idempotent — re-running does not duplicate', function () {
    app(Tenancy::class)->runFor($this->tenant, function () {
        $course = Course::factory()->create();
        Employee::factory()->count(3)->create();

        app(AssignCourseOrgWide::class)->handle($course, 'First run');
        app(AssignCourseOrgWide::class)->handle($course, 'Second run');

        expect(Enrollment::count())->toBe(3);
    });
});
