<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(Tenancy::class)->set($this->tenant->id);
    app(RolesAndPermissionsSeeder::class)->run();
});

/** A user holding $role, linked to an employee (the assigner's own person). */
function assignerWith(Tenant $tenant, string $role): array
{
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $employee = Employee::factory()->create(['user_id' => $user->id]);
    app(Tenancy::class)->runFor($tenant, fn () => $user->assignRole($role));

    return [$user, $employee];
}

it('lets a line manager assign to a direct report but not to others', function () {
    [$manager, $managerEmployee] = assignerWith($this->tenant, 'Manager');

    $report = Employee::factory()->create(['manager_id' => $managerEmployee->id]);
    $stranger = Employee::factory()->create(); // reports to nobody / someone else

    app(Tenancy::class)->runFor($this->tenant, function () use ($manager, $report, $stranger) {
        expect(Gate::forUser($manager)->allows('assign-course-to', $report))->toBeTrue()
            ->and(Gate::forUser($manager)->allows('assign-course-to', $stranger))->toBeFalse();
    });
});

it('lets an org-wide assigner (L&D) assign to anyone', function () {
    [$lnd] = assignerWith($this->tenant, 'L&D Manager');
    $anyone = Employee::factory()->create();

    app(Tenancy::class)->runFor($this->tenant, function () use ($lnd, $anyone) {
        expect(Gate::forUser($lnd)->allows('assign-course-to', $anyone))->toBeTrue();
    });
});

it('refuses a Content Administrator (no assign permission)', function () {
    [$ca] = assignerWith($this->tenant, 'Content Administrator');
    $anyone = Employee::factory()->create();

    app(Tenancy::class)->runFor($this->tenant, function () use ($ca, $anyone) {
        expect(Gate::forUser($ca)->allows('assign-course-to', $anyone))->toBeFalse();
    });
});
