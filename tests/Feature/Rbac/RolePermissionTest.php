<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** Seed the roles for a tenant, in that tenant's team context. */
function seedRolesFor(Tenant $tenant): void
{
    app(Tenancy::class)->runFor($tenant, fn () => app(RolesAndPermissionsSeeder::class)->run());
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    seedRolesFor($this->tenant);
});

it('assigns a role and resolves permissions through the char(26) morph key', function () {
    // The whole point of editing the spatie migration: model_id is char(26), so
    // a ULID user id round-trips and the morph relation actually resolves.
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    app(Tenancy::class)->runFor($this->tenant, function () use ($user) {
        $user->assignRole('Content Administrator');

        expect($user->hasRole('Content Administrator'))->toBeTrue()
            ->and($user->can('courses.manage'))->toBeTrue()
            ->and($user->can('learning_paths.manage'))->toBeTrue();
    });
});

it('is the acid test: Content Administrator sees nothing about people or activity', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    app(Tenancy::class)->runFor($this->tenant, function () use ($user) {
        $user->assignRole('Content Administrator');

        // Can author...
        expect($user->can('courses.manage'))->toBeTrue()
            ->and($user->can('learning_paths.manage'))->toBeTrue();

        // ...and can see NOTHING about who took what, who failed, or who anyone is.
        expect($user->can('enrollments.view'))->toBeFalse()
            ->and($user->can('reports.view'))->toBeFalse()
            ->and($user->can('employees.view'))->toBeFalse()
            ->and($user->can('users.manage'))->toBeFalse()
            ->and($user->can('roles.assign'))->toBeFalse()
            // The audit trail exposes who did what — off-limits to an author too.
            ->and($user->can('audit.view'))->toBeFalse();
    });
});

it('scopes roles per tenant — a role in Tenant A grants nothing in Tenant B', function () {
    $other = Tenant::factory()->create();
    seedRolesFor($other);

    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    // Granted L&D Manager in tenant A.
    app(Tenancy::class)->runFor($this->tenant, fn () => $user->assignRole('L&D Manager'));

    // In tenant A's context the permission holds...
    app(Tenancy::class)->runFor($this->tenant, function () use ($user) {
        expect($user->fresh()->can('reports.view'))->toBeTrue();
    });

    // ...but in tenant B's team context it grants nothing.
    app(Tenancy::class)->runFor($other, function () use ($user) {
        expect($user->fresh()->hasRole('L&D Manager'))->toBeFalse()
            ->and($user->fresh()->can('reports.view'))->toBeFalse();
    });
});

it('seeds Content Administrator with no forbidden permissions in the grant itself', function () {
    // Guard the policy at the source: even if a UI later mis-assigns, the role's
    // own grant must never contain a people/activity permission.
    $forbidden = ['enrollments.view', 'enrollments.assign', 'enrollments.assign_org',
        'reports.view', 'employees.view',
        'users.view', 'users.manage', 'roles.view', 'roles.assign', 'roles.manage'];

    app(Tenancy::class)->runFor($this->tenant, function () use ($forbidden) {
        $granted = Role::findByName('Content Administrator', 'web')
            ->permissions->pluck('name')->all();

        expect(array_intersect($forbidden, $granted))->toBe([]);
    });
});
