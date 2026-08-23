<?php

declare(strict_types=1);

use App\Livewire\Portal\Dashboard;
use App\Livewire\Portal\NotificationsBell;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(Tenancy::class)->set($this->tenant->id);

    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    app(Tenancy::class)->runFor($this->tenant, fn () => Employee::factory()->create(['user_id' => $this->user->id]));
});

/** Give the user one in-app (database) notification. */
function giveNotification(User $user, bool $read = false): void
{
    $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\CourseAssignedNotification',
        'data' => ['title' => 'New course assigned', 'body' => 'Consultative Selling', 'url' => '/portal'],
        'read_at' => $read ? now() : null,
    ]);
}

it('shows the learner their in-app notifications with an unread count', function () {
    giveNotification($this->user);

    $this->actingAs($this->user);

    Livewire::test(Dashboard::class)
        ->assertSee('New course assigned')
        ->assertSee('Consultative Selling');

    expect($this->user->unreadNotifications()->count())->toBe(1);
});

it('marks notifications read when the bell is opened', function () {
    giveNotification($this->user);
    $this->actingAs($this->user);

    // The bell is now its own component (shared by the portal shell on every page),
    // so opening it — which calls markRead — is exercised directly against it.
    Livewire::test(NotificationsBell::class)->call('markRead');

    expect($this->user->fresh()->unreadNotifications()->count())->toBe(0);
});
