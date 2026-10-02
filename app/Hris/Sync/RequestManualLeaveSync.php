<?php

declare(strict_types=1);

namespace App\Hris\Sync;

use App\Jobs\SyncLeaveJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * The "Sync leave now" button. Checks it is allowed (nothing already running, outside
 * the cooldown) and queues a manual sync attributed to the user.
 *
 * The cooldown is also enforced with an atomic cache key taken BEFORE queueing:
 * the SyncRun row only exists once the worker picks the job up, so without it two
 * quick clicks would both pass the "last manual run" check and both queue.
 */
final class RequestManualLeaveSync
{
    public function __construct(private readonly SyncLeave $sync) {}

    /**
     * @return string|null why it could not be started, or null when it has been queued
     */
    public function handle(Tenant $tenant, User $by): ?string
    {
        $reason = $this->sync->manualBlockedReason($tenant);

        if ($reason !== null) {
            return $reason;
        }

        $cooldown = (int) config('hris.leave.manual_cooldown_minutes', 5);

        if (! Cache::add('leave-sync:manual:'.$tenant->getKey(), true, now()->addMinutes($cooldown))) {
            return 'A leave sync was just requested. Please wait a few minutes before requesting another.';
        }

        SyncLeaveJob::dispatch((string) $tenant->getKey(), (string) $by->getKey());

        return null;
    }
}
