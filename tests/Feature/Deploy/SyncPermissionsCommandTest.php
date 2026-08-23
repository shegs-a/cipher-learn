<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/** Seed a tenant's roles the way a release would. */
function seedTenant(Tenant $tenant): void
{
    app(Tenancy::class)->runFor($tenant, fn () => app(RolesAndPermissionsSeeder::class)->run());
}

it('re-grants a permission that tenants were missing, across every tenant', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    seedTenant($a);
    seedTenant($b);

    // Model the pre-release state: Tenant Admin is missing `audit.view` in both
    // tenants (as it would be on a database seeded before that permission existed).
    foreach ([$a, $b] as $tenant) {
        app(Tenancy::class)->runFor($tenant, function () {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            Role::findByName('Tenant Admin', 'web')->revokePermissionTo('audit.view');
            expect(Role::findByName('Tenant Admin', 'web')->hasPermissionTo('audit.view'))->toBeFalse();
        });
    }

    $this->artisan('permissions:sync')->assertSuccessful();

    // After the sync, every tenant's Tenant Admin holds the permission again.
    foreach ([$a, $b] as $tenant) {
        app(Tenancy::class)->runFor($tenant, function () {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            expect(Role::findByName('Tenant Admin', 'web')->hasPermissionTo('audit.view'))->toBeTrue();
        });
    }
});

it('can target a single tenant', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $this->artisan('permissions:sync', ['--tenant' => $a->id])
        ->expectsOutputToContain($a->name)
        ->assertSuccessful();

    // Only tenant A was seeded; B has no roles of its own (team-scoped by tenant_id).
    expect(Role::where('name', 'Tenant Admin')->where('tenant_id', $b->id)->exists())->toBeFalse();
    expect(Role::where('name', 'Tenant Admin')->where('tenant_id', $a->id)->exists())->toBeTrue();
});

it('is idempotent — a second run changes nothing', function () {
    Tenant::factory()->create();

    $this->artisan('permissions:sync')->assertSuccessful();
    $before = Role::count();

    $this->artisan('permissions:sync')->assertSuccessful();

    expect(Role::count())->toBe($before);
});
