<?php

declare(strict_types=1);

use App\Hris\Data\LeaveData;
use App\Hris\Exceptions\HrisConnectionException;
use App\Hris\Sync\SyncLeave;
use App\Jobs\SyncLeaveJob;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\LeaveSyncFailedNotification;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Support\FakeLeaveAdapter;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Pin "now" so the sync window (today-7d .. today+90d) contains the fixed dates below.
    $this->travelTo('2026-10-02 12:00:00');
    FakeLeaveAdapter::register();
    $this->tenant = Tenant::factory()->create(['hris_adapter' => 'fake-leave']);
    $this->jane = app(Tenancy::class)->runFor($this->tenant, fn () => Employee::factory()->create(['external_id' => 'EMP-1']));
});

function leaveFor(string $employee, string $id, string $from, string $to, ?bool $current = null): LeaveData
{
    return new LeaveData($employee, $id, CarbonImmutable::parse($from), CarbonImmutable::parse($to), 'Annual', $current);
}

/** A user in the tenant holding the named role (permissions seeded for the tenant). */
function userWithRole(Tenant $tenant, string $role): User
{
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    app(Tenancy::class)->runFor($tenant, function () use ($user, $role) {
        app(RolesAndPermissionsSeeder::class)->run();
        $user->assignRole($role);
    });

    return $user;
}

function leaveRows(Tenant $tenant)
{
    return app(Tenancy::class)->runFor($tenant, fn () => EmployeeLeave::query()->orderBy('external_id')->get());
}

it('mirrors leave into the local table and reports what it did', function () {
    FakeLeaveAdapter::$leave = [
        leaveFor('EMP-1', 'LV-1', '2026-10-01', '2026-10-09'),
        leaveFor('EMP-1', 'LV-2', '2026-11-02', '2026-11-06'),
    ];

    $run = app(SyncLeave::class)->forTenant($this->tenant);

    expect($run->status)->toBe('completed')
        ->and($run->type)->toBe('leave_sync')
        ->and($run->trigger)->toBe('scheduled')
        ->and($run->stats)->toMatchArray(['adapter' => 'fake-leave', 'fetched' => 2, 'created' => 2, 'updated' => 0, 'removed' => 0, 'unmatched' => 0])
        ->and($run->stats['duration_ms'])->toBeInt()
        ->and($run->finished_at)->not->toBeNull()
        ->and(leaveRows($this->tenant))->toHaveCount(2);
});

it('is idempotent: a second run over unchanged data writes nothing', function () {
    FakeLeaveAdapter::$leave = [leaveFor('EMP-1', 'LV-1', '2026-10-01', '2026-10-09')];

    app(SyncLeave::class)->forTenant($this->tenant);
    $second = app(SyncLeave::class)->forTenant($this->tenant);

    expect($second->stats)->toMatchArray(['created' => 0, 'updated' => 0, 'unchanged' => 1, 'removed' => 0])
        ->and(leaveRows($this->tenant))->toHaveCount(1);
});

it('updates leave whose dates changed in the HRIS', function () {
    FakeLeaveAdapter::$leave = [leaveFor('EMP-1', 'LV-1', '2026-10-01', '2026-10-09')];
    app(SyncLeave::class)->forTenant($this->tenant);

    FakeLeaveAdapter::$leave = [leaveFor('EMP-1', 'LV-1', '2026-10-01', '2026-10-14')];
    $run = app(SyncLeave::class)->forTenant($this->tenant);

    expect($run->stats['updated'])->toBe(1)
        ->and(leaveRows($this->tenant)->first()->ends_on->toDateString())->toBe('2026-10-14');
});

it('removes leave the HRIS no longer returns (cancelled)', function () {
    FakeLeaveAdapter::$leave = [
        leaveFor('EMP-1', 'LV-1', '2026-10-01', '2026-10-09'),
        leaveFor('EMP-1', 'LV-2', '2026-11-02', '2026-11-06'),
    ];
    app(SyncLeave::class)->forTenant($this->tenant);

    FakeLeaveAdapter::$leave = [leaveFor('EMP-1', 'LV-1', '2026-10-01', '2026-10-09')];
    $run = app(SyncLeave::class)->forTenant($this->tenant);

    expect($run->stats['removed'])->toBe(1)
        ->and(leaveRows($this->tenant)->pluck('external_id')->all())->toBe(['LV-1']);
});

it('counts leave for employees it has not synced instead of guessing', function () {
    FakeLeaveAdapter::$leave = [leaveFor('EMP-UNKNOWN', 'LV-9', '2026-10-01', '2026-10-09')];

    $run = app(SyncLeave::class)->forTenant($this->tenant);

    expect($run->stats)->toMatchArray(['fetched' => 1, 'created' => 0, 'unmatched' => 1])
        ->and(leaveRows($this->tenant))->toHaveCount(0);
});

it('counts malformed records (end before start) as errors and carries on', function () {
    FakeLeaveAdapter::$leave = [
        leaveFor('EMP-1', 'LV-BAD', '2026-10-09', '2026-10-01'),
        leaveFor('EMP-1', 'LV-OK', '2026-10-01', '2026-10-09'),
    ];

    $run = app(SyncLeave::class)->forTenant($this->tenant);

    expect($run->stats)->toMatchArray(['errors' => 1, 'created' => 1]);
});

it('withholds removals when the HRIS returns nothing while we hold lots of leave (wipe guard)', function () {
    $today = $this->tenant->localToday();

    app(Tenancy::class)->runFor($this->tenant, function () use ($today) {
        EmployeeLeave::factory()->count(6)->for($this->jane)->sequence(fn ($s) => [
            'external_id' => 'LV-'.$s->index,
            'starts_on' => $today->addDays(1)->toDateString(),
            'ends_on' => $today->addDays(8)->toDateString(),
        ])->create(['tenant_id' => $this->tenant->id]);
    });

    Notification::fake();
    FakeLeaveAdapter::$leave = [];
    $run = app(SyncLeave::class)->forTenant($this->tenant);

    expect($run->status)->toBe('failed')
        ->and($run->stats)->toMatchArray(['wipe_guard_tripped' => true, 'removed' => 0, 'would_remove' => 6])
        ->and(leaveRows($this->tenant))->toHaveCount(6);
});

it('records a skipped run for an HR system without leave support', function () {
    FakeLeaveAdapter::$supportsLeave = false;

    $run = app(SyncLeave::class)->forTenant($this->tenant, 'manual');

    expect($run->status)->toBe('skipped')
        ->and($run->stats['reason'])->toContain('does not provide leave')
        ->and(FakeLeaveAdapter::$calls)->toBe(0);
});

it('marks the run failed and re-throws when the HRIS cannot be reached', function () {
    Notification::fake();
    FakeLeaveAdapter::$fails = true;

    expect(fn () => app(SyncLeave::class)->forTenant($this->tenant))->toThrow(HrisConnectionException::class);

    $run = app(Tenancy::class)->runFor($this->tenant, fn () => SyncRun::query()->latest('started_at')->first());

    expect($run->status)->toBe('failed')
        ->and($run->stats['error'])->toContain('timed out');
});

it('alerts HRIS-sync operators on failure — and only on failure', function () {
    Notification::fake();

    $admin = userWithRole($this->tenant, 'Tenant Admin');
    $learner = userWithRole($this->tenant, 'Learner');

    FakeLeaveAdapter::$leave = [leaveFor('EMP-1', 'LV-1', '2026-10-01', '2026-10-09')];
    app(SyncLeave::class)->forTenant($this->tenant);
    Notification::assertNothingSent();

    FakeLeaveAdapter::$fails = true;
    try {
        app(SyncLeave::class)->forTenant($this->tenant);
    } catch (Throwable) {
    }

    Notification::assertSentTo($admin, LeaveSyncFailedNotification::class);
    Notification::assertNotSentTo($learner, LeaveSyncFailedNotification::class);
});

it('records the trigger and who clicked for a manual run', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $run = app(SyncLeave::class)->forTenant($this->tenant, 'manual', $user);

    expect($run->trigger)->toBe('manual')
        ->and($run->triggered_by_user_id)->toBe($user->id)
        ->and($run->slot)->toBeNull();
});

it('keeps tenants apart: one tenant never sees or removes another tenant\'s leave', function () {
    $other = Tenant::factory()->create(['hris_adapter' => 'fake-leave']);
    app(Tenancy::class)->runFor($other, fn () => Employee::factory()->create(['external_id' => 'EMP-1']));

    FakeLeaveAdapter::$leave = [leaveFor('EMP-1', 'LV-1', '2026-10-01', '2026-10-09')];
    app(SyncLeave::class)->forTenant($this->tenant);
    app(SyncLeave::class)->forTenant($other);

    FakeLeaveAdapter::$leave = [];
    app(SyncLeave::class)->forTenant($this->tenant); // cancels only this tenant's leave

    expect(leaveRows($this->tenant))->toHaveCount(0)
        ->and(leaveRows($other))->toHaveCount(1);
});

describe('manual refresh guard', function () {
    it('allows a first manual run, then enforces a cooldown', function () {
        $sync = app(SyncLeave::class);

        expect($sync->manualBlockedReason($this->tenant))->toBeNull();

        $sync->forTenant($this->tenant, 'manual');

        expect($sync->manualBlockedReason($this->tenant))->toContain('wait');

        $this->travel(6)->minutes();

        expect($sync->manualBlockedReason($this->tenant))->toBeNull();
    });

    it('does not let a scheduled run trigger the manual cooldown', function () {
        app(SyncLeave::class)->forTenant($this->tenant, 'scheduled', null, '2026-10-02@06:00');

        expect(app(SyncLeave::class)->manualBlockedReason($this->tenant))->toBeNull();
    });

    it('refuses while a leave sync is already running', function () {
        app(Tenancy::class)->runFor($this->tenant, fn () => SyncRun::factory()->create([
            'type' => 'leave_sync', 'status' => 'running', 'started_at' => now()->subMinute(), 'finished_at' => null,
        ]));

        expect(app(SyncLeave::class)->manualBlockedReason($this->tenant))->toContain('already running');
    });

    it('ignores a run stuck in "running" for over half an hour', function () {
        app(Tenancy::class)->runFor($this->tenant, fn () => SyncRun::factory()->create([
            'type' => 'leave_sync', 'status' => 'running', 'started_at' => now()->subHour(), 'finished_at' => null,
        ]));

        expect(app(SyncLeave::class)->manualBlockedReason($this->tenant))->toBeNull();
    });

    it('runs the sync from the queued job, attributed to the user', function () {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        FakeLeaveAdapter::$leave = [leaveFor('EMP-1', 'LV-1', '2026-10-01', '2026-10-09')];

        SyncLeaveJob::dispatchSync($this->tenant->id, $user->id);

        $run = app(Tenancy::class)->runFor($this->tenant, fn () => SyncRun::query()->where('type', 'leave_sync')->first());

        expect($run->trigger)->toBe('manual')
            ->and($run->triggered_by_user_id)->toBe($user->id)
            ->and(leaveRows($this->tenant))->toHaveCount(1);
    });
});
