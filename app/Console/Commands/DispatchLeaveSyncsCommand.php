<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Hris\Sync\DispatchLeaveSyncs;
use Illuminate\Console\Command;

/**
 * Scheduler entry point for the twice-daily, per-tenant-timezone leave sync. Runs
 * every few minutes; does nothing unless a tenant's local 06:00/18:00 slot is due
 * and not yet run. See {@see DispatchLeaveSyncs}.
 */
class DispatchLeaveSyncsCommand extends Command
{
    protected $signature = 'hris:dispatch-leave-syncs';

    protected $description = 'Run leave syncs for tenants whose local 06:00/18:00 slot is due';

    public function handle(DispatchLeaveSyncs $dispatcher): int
    {
        $runs = $dispatcher->handle();

        foreach ($runs as $run) {
            $this->line(sprintf('%s: %s (%s)', $run->tenant_id, $run->status, $run->slot));
        }

        // Quiet by design when nothing was due; failures are recorded on the
        // SyncRun and alerted, not signalled by exit code (it runs every tick).
        return self::SUCCESS;
    }
}
