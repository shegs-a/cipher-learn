<?php

declare(strict_types=1);

use App\Livewire\Portal\Dashboard;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(Tenancy::class)->set($this->tenant->id);
    app(RolesAndPermissionsSeeder::class)->run();
});

it('requires authentication', function () {
    $this->get('/portal')->assertRedirect('/login');
});

it('shows the signed-in learner their assigned training', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    // Link a User to an Employee and give them an enrolment.
    [$employee, $course] = [null, null];
    app(Tenancy::class)->runFor($this->tenant, function () use ($user, &$employee, &$course) {
        $employee = Employee::factory()->create(['user_id' => $user->id]);
        $course = Course::factory()->create(['title' => 'Anti-Money Laundering']);
        Enrollment::factory()->create([
            'employee_id' => $employee->id,
            'course_id' => $course->id,
        ]);
    });

    $this->actingAs($user);

    Livewire::test(Dashboard::class)
        ->assertSee($user->name)
        ->assertSee('Anti-Money Laundering')
        // The product differentiator: every course shows why it was assigned.
        ->assertSee('Why you were assigned this');
});

it('shows an empty state when nothing is assigned', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    app(Tenancy::class)->runFor($this->tenant, fn () => Employee::factory()->create(['user_id' => $user->id]));

    $this->actingAs($user);

    Livewire::test(Dashboard::class)->assertSee('No training assigned yet.');
});

it('offers the admin switcher only to a user who can reach the panel', function () {
    // An admin viewing the portal sees the switch-to-admin link...
    $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    app(Tenancy::class)->runFor($this->tenant, fn () => $admin->assignRole('Tenant Admin'));

    $this->actingAs($admin);
    Livewire::test(Dashboard::class)->assertSee('Admin console');

    // ...a learner does not.
    $learner = User::factory()->create(['tenant_id' => $this->tenant->id]);
    app(Tenancy::class)->runFor($this->tenant, fn () => $learner->assignRole('Learner'));

    $this->actingAs($learner);
    Livewire::test(Dashboard::class)->assertDontSee('Admin console');
});
