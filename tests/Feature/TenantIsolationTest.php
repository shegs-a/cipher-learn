<?php

declare(strict_types=1);

use App\Models\Course;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenancy = app(Tenancy::class);
});

it('auto-stamps the current tenant on create', function () {
    $tenant = Tenant::factory()->create();
    $this->tenancy->set($tenant->id);

    $course = Course::factory()->create();

    expect($course->tenant_id)->toBe($tenant->id);
});

it('scopes every query to the current tenant', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $this->tenancy->set($a->id);
    Course::factory()->create(['title' => 'Tenant A course']);

    $this->tenancy->set($b->id);
    Course::factory()->create(['title' => 'Tenant B course']);

    // In B's context only B's course is visible...
    expect(Course::count())->toBe(1)
        ->and(Course::sole()->title)->toBe('Tenant B course');

    // ...and switching context flips the visible set.
    $this->tenancy->set($a->id);
    expect(Course::count())->toBe(1)
        ->and(Course::sole()->title)->toBe('Tenant A course');
});

it('cannot resolve another tenant\'s record by id', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $this->tenancy->set($a->id);
    $courseA = Course::factory()->create();

    $this->tenancy->set($b->id);

    expect(Course::find($courseA->id))->toBeNull();
});
