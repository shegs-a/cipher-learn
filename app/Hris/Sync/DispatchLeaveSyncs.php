<?php

declare(strict_types=1);

namespace App\Hris\Sync;

use App\Hris\HrisManager;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Runs each tenant's leave sync at ITS OWN 06:00 and 18:00 (see `hris.leave.slots`).
 *
 * Laravel's scheduler fires an entry at one wall-clock time in one timezone, which
 * cannot express "6am wherever each client is". So the scheduler calls this every
 * few minutes and it decides, per tenant, whether a slot is due:
 *
 *  - It looks at the tenant's local time and finds the most recent slot that has
 *    passed today. Only that one — after a long outage we run once, not once per
 *    missed slot.
 *  - A slot is keyed `Y-m-d@HH:MM` in tenant-local terms and recorded on the
 *    SyncRun. If a run already exists for the key (any outcome) it is not run
 *    again, so ticks are idempotent and a failed slot is not hammered every few
 *    minutes — the next slot, or the manual button, picks it up.
 *  - A missed tick (scheduler down at exactly 06:00) is caught by the next one.
 *  - Manual runs carry no slot, so they never consume a scheduled one.
 *  - Tenants whose HR system cannot report leave are skipped silently here; only a
 *    manual request records a "skipped" run for them.
 */
final class DispatchLeaveSyncs
{
    public function __construct(
        private readonly HrisManager $hris,
        private readonly SyncLeave $sync,
        private readonly Tenancy $tenancy,
    ) {}

    /**
     * @return list<SyncRun> the runs started this tick
     */
    public function handle(?CarbonImmutable $now = null): array
    {
        $runs = [];

        foreach (Tenant::query()->orderBy('name')->get() as $tenant) {
            try {
                if ($this->hris->leaveSourceFor($tenant) === null) {
                    continue;
                }

                $slot = $this->dueSlot($tenant, $now);

                if ($slot === null || $this->alreadyHandled($tenant, $slot)) {
                    continue;
                }

                $runs[] = $this->sync->forTenant($tenant, 'scheduled', null, $slot);
            } catch (Throwable) {
                // One tenant's HR system being down (already recorded on its
                // SyncRun, and alerted) must never stop the other tenants.
                continue;
            }
        }

        return $runs;
    }

    /**
     * The most recent slot that has passed in the tenant's local day, as
     * "Y-m-d@HH:MM", or null before the day's first slot.
     */
    public function dueSlot(Tenant $tenant, ?CarbonImmutable $now = null): ?string
    {
        $local = $tenant->localNow($now);

        /** @var list<string> $slots */
        $slots = (array) config('hris.leave.slots', ['06:00', '18:00']);
        rsort($slots);

        foreach ($slots as $slot) {
            if ($local->gte($local->setTimeFromTimeString($slot))) {
                return $local->toDateString().'@'.$slot;
            }
        }

        return null;
    }

    private function alreadyHandled(Tenant $tenant, string $slot): bool
    {
        return (bool) $this->tenancy->runFor($tenant, fn (): bool => SyncRun::query()
            ->where('type', SyncLeave::TYPE)
            ->where('slot', $slot)
            ->exists() || $this->sync->isRunning());
    }
}
