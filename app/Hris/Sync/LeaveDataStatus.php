<?php

declare(strict_types=1);

namespace App\Hris\Sync;

use App\Hris\HrisManager;
use App\Models\SyncRun;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * How fresh a tenant's local leave data is. One definition of "stale" shared by the
 * eligibility check (which fails open and flags it) and the admin UI (which shows
 * "last refreshed X ago"), so the two can never disagree.
 */
final class LeaveDataStatus
{
    public function __construct(private readonly HrisManager $hris) {}

    /** Whether the tenant's HR system provides leave at all. */
    public function isTracked(Tenant $tenant): bool
    {
        try {
            return $this->hris->leaveSourceFor($tenant) !== null;
        } catch (Throwable) {
            return false; // a misconfigured adapter key: nothing to be fresh or stale
        }
    }

    /** When the last SUCCESSFUL leave sync finished, or null if there never was one. */
    public function lastSuccessfulSync(Tenant $tenant): ?Carbon
    {
        /** @var SyncRun|null $last */
        $last = SyncRun::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('type', SyncLeave::TYPE)
            ->where('status', 'completed')
            ->latest('finished_at')
            ->first();

        return $last?->finished_at;
    }

    /**
     * Stale = the tenant HAS a leave feed but its last successful sync is older than
     * `hris.leave.stale_after_hours` (or never happened). A tenant with no leave feed
     * is not stale — there is nothing to check.
     */
    public function isStale(Tenant $tenant, ?CarbonImmutable $now = null): bool
    {
        if (! $this->isTracked($tenant)) {
            return false;
        }

        $last = $this->lastSuccessfulSync($tenant);

        if ($last === null) {
            return true;
        }

        $threshold = ($now ?? CarbonImmutable::now())->subHours((int) config('hris.leave.stale_after_hours', 13));

        return $last->lt($threshold);
    }
}
