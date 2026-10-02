<?php

declare(strict_types=1);

use App\Hris\Adapters\ExampleHrAdapter;
use App\Hris\Adapters\MockHrisAdapter;

return [

    /*
    |--------------------------------------------------------------------------
    | Default adapter
    |--------------------------------------------------------------------------
    |
    | Used when a tenant has no `hris_adapter` set. The mock is the default on
    | purpose: CipherLearn is an independent LMS that integrates WITH an HR
    | system, not an add-on to one, so it must be fully demonstrable and testable
    | with no HR system present at all.
    |
    */

    'default' => env('HRIS_DEFAULT_ADAPTER', 'mock'),

    /*
    |--------------------------------------------------------------------------
    | Adapter registry
    |--------------------------------------------------------------------------
    |
    | Maps the key stored in `tenants.hris_adapter` to the class implementing it.
    | Adding a new HR system means adding one class and one line here — nothing
    | downstream of the port changes.
    |
    */

    'adapters' => [
        'mock' => MockHrisAdapter::class,
        'example' => ExampleHrAdapter::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mock adapter
    |--------------------------------------------------------------------------
    |
    | `seed` keeps the generated org deterministic: the same 45 people, the same
    | reporting lines, every run. Demos and tests both depend on that stability,
    | so change it only when you intend the fixture org to change.
    |
    */

    'mock' => [
        'employee_count' => (int) env('HRIS_MOCK_EMPLOYEE_COUNT', 45),
        'seed' => (int) env('HRIS_MOCK_SEED', 20260722),
    ],

    /*
    |--------------------------------------------------------------------------
    | Employee sync — the mass-exit guard
    |--------------------------------------------------------------------------
    |
    | The sync marks anyone the HR system stops listing as `exited`. If a live
    | HR API ever returns an empty or truncated directory, that sweep would exit
    | the entire active workforce in one run. The guard withholds the sweep and
    | flags the run when the fraction of the active workforce that would leave
    | exceeds `exit_guard_threshold`, but only once the workforce is at least
    | `exit_guard_min_active` (below that, one leaver is a large, meaningless
    | fraction). Re-run with `--force` for a genuine large reduction in force.
    |
    */

    'sync' => [
        'exit_guard_threshold' => (float) env('HRIS_EXIT_GUARD_THRESHOLD', 0.20),
        'exit_guard_min_active' => (int) env('HRIS_EXIT_GUARD_MIN_ACTIVE', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Leave sync
    |--------------------------------------------------------------------------
    |
    | Leave is mirrored into `employee_leaves` twice a day, at each tenant's OWN
    | local `slots` (24h HH:MM, in the tenant's timezone). Assignment reads only
    | that local table — never the HRIS — so a vendor outage cannot block it.
    |
    | `window_*` bound the date range requested from the HRIS. `stale_after_hours`
    | is how old the last successful sync may be before leave data is treated as
    | stale (assignment still proceeds — fail open — but the audit row says so);
    | with runs 12h apart, 13h tolerates one normal gap plus a little slack.
    | `manual_cooldown_minutes` stops repeated clicks of "Sync leave now" from
    | hammering the HR API.
    |
    */

    'leave' => [
        'slots' => ['06:00', '18:00'],
        'window_past_days' => 7,
        'window_future_days' => 90,
        'stale_after_hours' => (int) env('HRIS_LEAVE_STALE_AFTER_HOURS', 13),
        'manual_cooldown_minutes' => (int) env('HRIS_LEAVE_MANUAL_COOLDOWN', 5),
        // Abort (rather than delete) when the HRIS returns nothing yet we hold at
        // least this many current/upcoming leave rows — an empty feed is suspicious.
        'wipe_guard_min_rows' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | ExampleHR adapter
    |--------------------------------------------------------------------------
    |
    | Built strictly against the PUBLIC documentation at https://docs.example.com
    | — no proprietary schemas, endpoints or credentials. Per-tenant credentials
    | live in `tenants.settings`, never here; this holds only non-secret defaults.
    |
    */

    'example' => [
        'base_url' => env('EXAMPLEHR_BASE_URL', 'https://api.example.com'),
        'timeout' => (int) env('EXAMPLEHR_TIMEOUT', 15),
        'retry_times' => (int) env('EXAMPLEHR_RETRY_TIMES', 2),
        'retry_sleep_ms' => (int) env('EXAMPLEHR_RETRY_SLEEP_MS', 200),
        'page_size' => (int) env('EXAMPLEHR_PAGE_SIZE', 100),
    ],

];
