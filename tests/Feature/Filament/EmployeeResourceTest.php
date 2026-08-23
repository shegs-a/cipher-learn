<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Models\Employee;
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
    $this->tenant = Tenant::factory()->create(['hris_adapter' => 'mock']);
    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    actingAs($this->admin);
    app(Tenancy::class)->set($this->tenant->id);

    app(RolesAndPermissionsSeeder::class)->run();
    $this->admin->assignRole('Tenant Admin');
});

it('lists synced employees but never lets them be created', function () {
    Employee::factory()->create(['first_name' => 'Adaeze', 'last_name' => 'Okafor']);

    Livewire::test(ListEmployees::class)
        ->assertCanSeeTableRecords(Employee::all());

    // People are HRIS-owned: the resource must not expose a create path.
    expect(EmployeeResource::canCreate())->toBeFalse();
});

it('defaults the table to active employees, hiding leavers', function () {
    $active = Employee::factory()->create(['status' => EmployeeStatus::Active]);
    $leaver = Employee::factory()->create(['status' => EmployeeStatus::Exited]);

    Livewire::test(ListEmployees::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$leaver]);
});

it('runs a sync from the Sync now action and reports the outcome', function () {
    expect(Employee::count())->toBe(0);

    Livewire::test(ListEmployees::class)
        ->callAction('sync')
        ->assertHasNoActionErrors()
        ->assertNotified();

    // The mock adapter's whole org is now present, proving the action wired the
    // real sync service to the current tenant.
    expect(Employee::count())->toBe((int) config('hris.mock.employee_count'));
});

it('only ever shows the current tenant\'s employees', function () {
    $mine = Employee::factory()->create();

    $otherTenant = Tenant::factory()->create();
    app(Tenancy::class)->runFor($otherTenant, function () {
        Employee::factory()->create();
    });

    Livewire::test(ListEmployees::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCountTableRecords(1);
});
