<?php

declare(strict_types=1);

use App\Filament\Resources\RoleResource;
use App\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

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

it('creates a custom role with the chosen permissions, scoped to the tenant', function () {
    Livewire::test(CreateRole::class)
        ->fillForm([
            'name' => 'Auditor',
            'permissions' => ['reports.view', 'enrollments.view'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    app(Tenancy::class)->runFor($this->tenant, function () {
        $role = Role::findByName('Auditor', 'web');

        expect($role->tenant_id)->toBe($this->tenant->id)
            ->and($role->permissions->pluck('name')->sort()->values()->all())
            ->toBe(['enrollments.view', 'reports.view']);
    });
});

it('refuses to delete a built-in role but allows deleting a custom one', function () {
    app(Tenancy::class)->runFor($this->tenant, function () {
        $builtIn = Role::findByName('Tenant Admin', 'web');
        $custom = Role::create(['name' => 'Temporary', 'guard_name' => 'web']);

        expect(RoleResource::canDelete($builtIn))->toBeFalse()
            ->and(RoleResource::canDelete($custom))->toBeTrue();
    });
});

it('lists only the current tenant\'s roles', function () {
    $otherTenant = Tenant::factory()->create();
    app(Tenancy::class)->runFor($otherTenant, fn () => app(RolesAndPermissionsSeeder::class)->run());

    // Five seeded roles for this tenant; the other tenant's are not visible.
    Livewire::test(RoleResource\Pages\ListRoles::class)
        ->assertCountTableRecords(count(RolesAndPermissionsSeeder::ROLES));
});

it('hides the Roles resource from a Content Administrator', function () {
    $ca = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $ca->assignRole('Content Administrator');
    actingAs($ca);

    expect(RoleResource::canViewAny())->toBeFalse();
});

it('creates an LMS admin by elevating an employee from the Roles screen', function () {
    $employee = Employee::factory()->create([
        'first_name' => 'Kelechi',
        'last_name' => 'Umeh',
        'email' => 'kelechi@example.test',
        'user_id' => null,
    ]);

    Livewire::test(RoleResource\Pages\ListRoles::class)
        ->callAction('createLmsAdmin', data: [
            'employee_id' => $employee->id,
            'role' => 'L&D Manager',
            'password' => 'secret-pass',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $employee->refresh();

    expect($employee->user_id)->not->toBeNull()
        ->and($employee->user->email)->toBe('kelechi@example.test')
        ->and($employee->user->hasRole('L&D Manager'))->toBeTrue();
});
