<?php

declare(strict_types=1);

use App\Livewire\Portal\Dashboard;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(Tenancy::class)->set($this->tenant->id);
});

/**
 * The shared portal shell (rendered here via the Dashboard page it wraps) drives
 * navigation for every portal screen, so its conditional destinations are worth
 * pinning down.
 */
it('always shows the core nav destinations', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    app(Tenancy::class)->runFor($this->tenant, fn () => Employee::factory()->create(['user_id' => $user->id]));
    actingAs($user);

    Livewire::test(Dashboard::class)
        ->assertSee('My Learning')
        ->assertSee('Learning paths')
        ->assertSee('Certificates');
});

it('hides "My team" from someone who neither manages anyone nor can assign', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    // An employee with no direct reports and no assign permission.
    app(Tenancy::class)->runFor($this->tenant, fn () => Employee::factory()->create(['user_id' => $user->id]));
    actingAs($user);

    Livewire::test(Dashboard::class)->assertDontSee('My team');
});

it('shows "My team" to a manager (someone with direct reports)', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    app(Tenancy::class)->runFor($this->tenant, function () use ($user) {
        $manager = Employee::factory()->create(['user_id' => $user->id]);
        // A direct report makes this user a manager → the team nav appears.
        Employee::factory()->create(['manager_id' => $manager->id]);
    });
    actingAs($user);

    Livewire::test(Dashboard::class)->assertSee('My team');
});
