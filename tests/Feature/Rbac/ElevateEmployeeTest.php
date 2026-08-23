<?php

declare(strict_types=1);

use App\Actions\Rbac\ElevateEmployeeToRole;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(Tenancy::class)->set($this->tenant->id);
    app(RolesAndPermissionsSeeder::class)->run();
});

it('provisions a login for an employee and assigns the role', function () {
    $employee = null;
    app(Tenancy::class)->runFor($this->tenant, function () use (&$employee) {
        $employee = Employee::factory()->create([
            'email' => 'chidi@example.test',
            'user_id' => null,
        ]);

        $user = app(ElevateEmployeeToRole::class)->handle($employee, 'L&D Manager', 'initial-pass');

        expect($user->email)->toBe('chidi@example.test')
            ->and($user->tenant_id)->toBe($this->tenant->id)
            ->and(Hash::check('initial-pass', $user->password))->toBeTrue()
            ->and($user->hasRole('L&D Manager'))->toBeTrue()
            // The employee stays the person, now linked to the login.
            ->and($employee->fresh()->user_id)->toBe($user->id);
    });
});

it('reuses an existing login and just adds the role', function () {
    app(Tenancy::class)->runFor($this->tenant, function () {
        $employee = Employee::factory()->create(['email' => 'ada@example.test']);
        $first = app(ElevateEmployeeToRole::class)->handle($employee, 'Manager', 'pw');

        // Elevate again with a different role — no new login, both roles held.
        $second = app(ElevateEmployeeToRole::class)->handle($employee->fresh(), 'L&D Manager');

        expect($second->id)->toBe($first->id)
            ->and(User::count())->toBe(1)
            ->and($second->hasRole('Manager'))->toBeTrue()
            ->and($second->hasRole('L&D Manager'))->toBeTrue();
    });
});

it('refuses to elevate an employee with no email', function () {
    app(Tenancy::class)->runFor($this->tenant, function () {
        $employee = Employee::factory()->create(['email' => null, 'user_id' => null]);

        expect(fn () => app(ElevateEmployeeToRole::class)->handle($employee, 'Manager'))
            ->toThrow(RuntimeException::class);
    });
});
