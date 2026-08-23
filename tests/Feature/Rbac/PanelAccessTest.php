<?php

declare(strict_types=1);

use App\Filament\Resources\CourseResource;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\SyncRunResource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/** Act as a user in the tenant's team context, with roles seeded. */
function actingWithRole(Tenant $tenant, string $role): User
{
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    app(Tenancy::class)->set($tenant->id);
    app(RolesAndPermissionsSeeder::class)->run();
    $user->assignRole($role);

    actingAs($user);

    return $user;
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->panel = Filament::getPanel('admin');
});

it('keeps a learner-only user out of the admin panel', function () {
    $learner = actingWithRole($this->tenant, 'Learner');

    // A Learner holds no admin-surface permission, so the panel is closed to them
    // — they belong in the learner portal.
    expect($learner->canAccessPanel($this->panel))->toBeFalse();
});

it('lets a Content Administrator into the panel', function () {
    $ca = actingWithRole($this->tenant, 'Content Administrator');

    expect($ca->canAccessPanel($this->panel))->toBeTrue();
});

it('is the acid test in the UI: Content Administrator sees courses, not people', function () {
    actingWithRole($this->tenant, 'Content Administrator');

    // Can reach the catalogue...
    expect(CourseResource::canViewAny())->toBeTrue()
        // ...but People and the sync history are invisible.
        ->and(EmployeeResource::canViewAny())->toBeFalse()
        ->and(SyncRunResource::canViewAny())->toBeFalse();
});

it('lets an L&D Manager see people', function () {
    actingWithRole($this->tenant, 'L&D Manager');

    expect(EmployeeResource::canViewAny())->toBeTrue()
        ->and(CourseResource::canViewAny())->toBeTrue();
});

it('gives a platform super-admin everything via the Gate::before bypass', function () {
    // No roles at all — the boolean flag alone must open every gate.
    $super = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_platform_admin' => true,
    ]);

    app(Tenancy::class)->set($this->tenant->id);
    actingAs($super);

    expect($super->can('courses.manage'))->toBeTrue()
        ->and($super->can('users.manage'))->toBeTrue()
        ->and($super->can('roles.assign'))->toBeTrue()
        ->and($super->canAccessPanel($this->panel))->toBeTrue()
        ->and(EmployeeResource::canViewAny())->toBeTrue()
        ->and($super->hasRole('Tenant Admin'))->toBeFalse(); // power without a role
});
