<?php

declare(strict_types=1);

use App\Hris\Sync\DispatchLeaveSyncs;
use App\Hris\Sync\SyncLeave;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLeaveAdapter;

uses(RefreshDatabase::class);

beforeEach(function () {
    FakeLeaveAdapter::register();
});

/** Make a tenant on the fake HR system in the given timezone. */
function leaveTenant(string $timezone, string $name = 'Acme'): Tenant
{
    return Tenant::factory()->create(['name' => $name, 'timezone' => $timezone, 'hris_adapter' => 'fake-leave']);
}

/** @return list<string> the slots of every leave run a tenant has, oldest first */
function slotsFor(Tenant $tenant): array
{
    return app(Tenancy::class)->runFor($tenant, fn () => SyncRun::query()
        ->where('type', 'leave_sync')->orderBy('started_at')->pluck('slot')->all());
}

function tick(string $utc): array
{
    return app(DispatchLeaveSyncs::class)->handle(CarbonImmutable::parse($utc, 'UTC'));
}

it('does nothing before the first slot of the tenant\'s local day', function () {
    $tenant = leaveTenant('Africa/Lagos'); // UTC+1

    tick('2026-10-02 04:59:00'); // 05:59 in Lagos

    expect(slotsFor($tenant))->toBe([])
        ->and(FakeLeaveAdapter::$calls)->toBe(0);
});

it('runs the 06:00 slot at 06:00 tenant-local, not 06:00 UTC', function () {
    $lagos = leaveTenant('Africa/Lagos', 'Lagos Co');

    tick('2026-10-02 05:00:00'); // 06:00 in Lagos

    expect(slotsFor($lagos))->toBe(['2026-10-02@06:00']);
});

it('runs the 18:00 slot in the evening', function () {
    $tenant = leaveTenant('Africa/Lagos');

    tick('2026-10-02 05:00:00');
    tick('2026-10-02 17:00:00'); // 18:00 Lagos

    expect(slotsFor($tenant))->toBe(['2026-10-02@06:00', '2026-10-02@18:00']);
});

it('serves tenants in different timezones at their own local times', function () {
    $lagos = leaveTenant('Africa/Lagos', 'Lagos Co');     // UTC+1
    $nairobi = leaveTenant('Africa/Nairobi', 'Nairobi Co'); // UTC+3

    // 03:00 UTC = 06:00 Nairobi, but only 04:00 in Lagos.
    tick('2026-10-02 03:00:00');
    expect(slotsFor($nairobi))->toBe(['2026-10-02@06:00'])
        ->and(slotsFor($lagos))->toBe([]);

    // 05:00 UTC = 06:00 Lagos.
    tick('2026-10-02 05:00:00');
    expect(slotsFor($lagos))->toBe(['2026-10-02@06:00']);
});

it('is idempotent: repeated ticks inside a slot run it once', function () {
    $tenant = leaveTenant('UTC');

    tick('2026-10-02 06:00:00');
    tick('2026-10-02 06:10:00');
    tick('2026-10-02 06:20:00');
    tick('2026-10-02 11:00:00');

    expect(slotsFor($tenant))->toBe(['2026-10-02@06:00'])
        ->and(FakeLeaveAdapter::$calls)->toBe(1);
});

it('catches a missed tick: a 06:00 slot noticed at 06:40 still runs, as the 06:00 slot', function () {
    $tenant = leaveTenant('UTC');

    tick('2026-10-02 06:40:00');

    expect(slotsFor($tenant))->toBe(['2026-10-02@06:00']);
});

it('runs only the latest due slot after a long outage, not every missed one', function () {
    $tenant = leaveTenant('UTC');

    tick('2026-10-02 19:30:00'); // both 06:00 and 18:00 have passed

    expect(slotsFor($tenant))->toBe(['2026-10-02@18:00']);
});

it('does not retry a failed slot on every tick', function () {
    $tenant = leaveTenant('UTC');
    FakeLeaveAdapter::$fails = true;

    tick('2026-10-02 06:00:00');
    tick('2026-10-02 06:10:00');
    tick('2026-10-02 06:20:00');

    expect(FakeLeaveAdapter::$calls)->toBe(1)
        ->and(slotsFor($tenant))->toBe(['2026-10-02@06:00']);
});

it('keeps going when one tenant\'s HR system is down', function () {
    $broken = leaveTenant('UTC', 'A Broken');
    $healthy = Tenant::factory()->create(['name' => 'B Healthy', 'timezone' => 'UTC', 'hris_adapter' => 'mock']);

    FakeLeaveAdapter::$fails = true;
    tick('2026-10-02 06:00:00');

    expect(slotsFor($broken))->toBe(['2026-10-02@06:00'])
        ->and(slotsFor($healthy))->toBe(['2026-10-02@06:00']);
});

it('lets a manual run happen without consuming the scheduled slot', function () {
    $tenant = leaveTenant('UTC');

    app(SyncLeave::class)->forTenant($tenant, 'manual');
    tick('2026-10-02 06:00:00');

    expect(slotsFor($tenant))->toBe([null, '2026-10-02@06:00']);
});

it('skips tenants whose HR system has no leave support, silently', function () {
    $tenant = leaveTenant('UTC');
    FakeLeaveAdapter::$supportsLeave = false;

    tick('2026-10-02 06:00:00');

    expect(slotsFor($tenant))->toBe([]);
});

it('does not start a slot while a leave sync is already running', function () {
    $tenant = leaveTenant('UTC');
    app(Tenancy::class)->runFor($tenant, fn () => SyncRun::factory()->create([
        'type' => 'leave_sync', 'status' => 'running', 'started_at' => now()->subMinute(), 'finished_at' => null,
    ]));

    tick('2026-10-02 06:00:00');

    expect(slotsFor($tenant))->toBe([null]); // only the pre-existing running row
});

it('handles the day a timezone changes its clocks: still one 06:00 run per local day', function () {
    // Europe/London leaves BST on 2026-10-25 (clocks back at 02:00).
    $tenant = leaveTenant('Europe/London');

    tick('2026-10-24 05:00:00'); // 06:00 BST
    tick('2026-10-25 06:00:00'); // 06:00 GMT (the day clocks went back)
    tick('2026-10-25 06:30:00');

    expect(slotsFor($tenant))->toBe(['2026-10-24@06:00', '2026-10-25@06:00']);
});

it('registers the dispatcher on the scheduler', function () {
    $this->artisan('schedule:list')
        ->assertSuccessful()
        ->expectsOutputToContain('hris:dispatch-leave-syncs');
});

it('exposes the tenant-local date and time', function () {
    $tenant = leaveTenant('Pacific/Auckland'); // UTC+13 in October

    $now = CarbonImmutable::parse('2026-10-02 12:00:00', 'UTC');

    expect($tenant->localToday($now)->toDateString())->toBe('2026-10-03')
        ->and($tenant->localNow($now)->format('H:i'))->toBe('01:00');
});
