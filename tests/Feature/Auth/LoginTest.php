<?php

declare(strict_types=1);

use App\Livewire\Auth\Login;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(Tenancy::class)->set($this->tenant->id);
    app(RolesAndPermissionsSeeder::class)->run();
});

function makeUser(Tenant $tenant, string $role, bool $active = true): User
{
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'password' => Hash::make('correct-horse'),
        'is_active' => $active,
    ]);
    app(Tenancy::class)->runFor($tenant, fn () => $user->assignRole($role));

    return $user;
}

it('routes an admin-capable user to the admin panel', function () {
    makeUser($this->tenant, 'Tenant Admin');

    Livewire::test(Login::class)
        ->set('email', User::first()->email)
        ->set('password', 'correct-horse')
        ->call('authenticate')
        ->assertRedirect('/admin');

    expect(auth()->check())->toBeTrue();
});

it('routes a learner to the learner portal, not the admin panel', function () {
    makeUser($this->tenant, 'Learner');

    Livewire::test(Login::class)
        ->set('email', User::first()->email)
        ->set('password', 'correct-horse')
        ->call('authenticate')
        ->assertRedirect(route('portal'));
});

it('lands a plain Manager on the learner portal, not the admin panel', function () {
    // Sprint 5 settles the Sprint 4 open item: a line Manager's home is "My Team"
    // in the portal. They still hold panel-read permissions (so the switcher
    // works), but login sends them to the portal.
    makeUser($this->tenant, 'Manager');

    Livewire::test(Login::class)
        ->set('email', User::first()->email)
        ->set('password', 'correct-horse')
        ->call('authenticate')
        ->assertRedirect(route('portal'));
});

it('still lets a Manager reach the admin panel via the switcher', function () {
    // Landing on the portal must not revoke panel *access* — employees.view etc.
    // still admit them; only their post-login home changed.
    $manager = makeUser($this->tenant, 'Manager');

    $this->actingAs($manager)->get('/admin')->assertSuccessful();
});

it('rejects an inactive user even with the right password', function () {
    makeUser($this->tenant, 'Tenant Admin', active: false);

    Livewire::test(Login::class)
        ->set('email', User::first()->email)
        ->set('password', 'correct-horse')
        ->call('authenticate')
        ->assertHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('rejects a wrong password', function () {
    makeUser($this->tenant, 'Tenant Admin');

    Livewire::test(Login::class)
        ->set('email', User::first()->email)
        ->set('password', 'wrong')
        ->call('authenticate')
        ->assertHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('redirects a guest from the admin panel to the unified login', function () {
    $this->get('/admin')->assertRedirect('/login');
});

it('actually admits an authenticated admin to the panel (not a 403)', function () {
    // Regression: canAccessPanel runs in Filament's Authenticate middleware,
    // BEFORE BindCurrentTenant, so the permission check needs its own tenant
    // context or a legitimate admin gets a 403 right after login.
    $admin = makeUser($this->tenant, 'Tenant Admin');

    $this->actingAs($admin)->get('/admin')->assertSuccessful();
});

it('forbids a learner from loading the admin panel', function () {
    $learner = makeUser($this->tenant, 'Learner');

    $this->actingAs($learner)->get('/admin')->assertForbidden();
});

it('logs a user out and returns them to the login', function () {
    $user = makeUser($this->tenant, 'Tenant Admin');

    $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});
