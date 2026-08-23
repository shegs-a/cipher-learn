<?php

declare(strict_types=1);

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    actingAs($this->admin);
    app(Tenancy::class)->set($this->tenant->id);

    app(RolesAndPermissionsSeeder::class)->run();
    $this->admin->assignRole('Tenant Admin');
});

/** Make a user in the tenant and give them a role, in team context. */
function userWithRole(Tenant $tenant, string $role): User
{
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    app(Tenancy::class)->runFor($tenant, fn () => $user->assignRole($role));

    return $user;
}

it('lists only employees with an elevated (non-Learner) role', function () {
    $manager = userWithRole($this->tenant, 'L&D Manager');
    $learner = userWithRole($this->tenant, 'Learner');
    $noRole = User::factory()->create(['tenant_id' => $this->tenant->id]);

    Livewire::test(ListUsers::class)
        // The Tenant Admin (from beforeEach) and the L&D Manager are elevated...
        ->assertCanSeeTableRecords([$this->admin, $manager])
        // ...a learner-only login and a role-less login are not LMS admins.
        ->assertCanNotSeeTableRecords([$learner, $noRole]);
});

it('scopes the LMS admins list to the current tenant', function () {
    $mine = userWithRole($this->tenant, 'L&D Manager');

    $otherTenant = Tenant::factory()->create();
    app(Tenancy::class)->runFor($otherTenant, fn () => app(RolesAndPermissionsSeeder::class)->run());
    userWithRole($otherTenant, 'Tenant Admin');

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$this->admin, $mine])
        ->assertCountTableRecords(2); // admin + mine, never the other tenant's
});

it('does not allow creating an admin from this screen (that lives on the Roles screen)', function () {
    expect(UserResource::canCreate())->toBeFalse();
});

it('is hidden from a Content Administrator', function () {
    $ca = User::factory()->create(['tenant_id' => $this->tenant->id]);
    app(Tenancy::class)->runFor($this->tenant, fn () => $ca->assignRole('Content Administrator'));
    actingAs($ca);

    expect(UserResource::canViewAny())->toBeFalse();
});
