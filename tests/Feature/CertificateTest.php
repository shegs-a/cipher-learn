<?php

declare(strict_types=1);

use App\Models\Certificate;
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
    $this->employee = Employee::factory()->create();
    $this->course = Course::factory()->create();
    $this->enrollment = Enrollment::factory()->create([
        'employee_id' => $this->employee->id,
        'course_id' => $this->course->id,
    ]);
});

it('stores a recertification expiry beyond the 2038 epoch ceiling', function () {
    // This is the point of the TIMESTAMP -> DATETIME change. On MySQL 8 (what CI
    // and production run) a TIMESTAMP column would reject or truncate a date past
    // 2038-01-19; DATETIME holds it. A long-dated recert certificate is exactly
    // where that overflow would otherwise bite, so we prove the round-trip.
    $certificate = Certificate::factory()->create([
        'enrollment_id' => $this->enrollment->id,
        'employee_id' => $this->employee->id,
        'course_id' => $this->course->id,
        'expires_at' => '2099-12-31 00:00:00',
    ]);

    // Re-read from the database so we test what was actually persisted, not the
    // in-memory value the factory set.
    expect($certificate->fresh()->expires_at->year)->toBe(2099);
});
