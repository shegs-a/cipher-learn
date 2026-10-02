<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Hris\Sync\SyncLeave;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pull approved leave from each tenant's HR system into the LMS, on demand.
 *
 * The routine twice-a-day runs come from `hris:dispatch-leave-syncs`; this is the
 * manual/CLI path (recorded as a `manual` run). Like the employee sync it needs
 * `--tenant` or `--all` so a full run is never accidental.
 */
class SyncLeaveCommand extends Command
{
    protected $signature = 'hris:sync-leave
                            {--tenant= : Sync one tenant, by slug or id}
                            {--all : Sync every tenant}';

    protected $description = "Sync employee leave from each tenant's HR system into the LMS";

    public function handle(SyncLeave $sync): int
    {
        $identifier = $this->option('tenant');

        if ($this->option('all') === (bool) $identifier) {
            $this->components->error('Specify exactly one of --tenant=<slug|id> or --all.');

            return self::FAILURE;
        }

        $tenants = $this->option('all')
            ? Tenant::query()->orderBy('name')->get()
            : Tenant::query()->where('slug', $identifier)->orWhere('id', $identifier)->get();

        if ($tenants->isEmpty()) {
            if ($this->option('all')) {
                $this->components->warn('No tenants exist yet — nothing to sync.');

                return self::SUCCESS;
            }

            $this->components->error("No tenant matched [{$identifier}].");

            return self::FAILURE;
        }

        $rows = [];
        $failed = false;

        foreach ($tenants as $tenant) {
            try {
                $run = $sync->forTenant($tenant, 'manual');
                $stats = $run->stats ?? [];

                $rows[] = [
                    $tenant->name,
                    $run->status,
                    $stats['fetched'] ?? 0,
                    $stats['created'] ?? 0,
                    $stats['updated'] ?? 0,
                    $stats['removed'] ?? 0,
                    $stats['unmatched'] ?? 0,
                ];

                $failed = $failed || $run->status === 'failed';
            } catch (Throwable $e) {
                $rows[] = [$tenant->name, 'failed: '.$e->getMessage(), 0, 0, 0, 0, 0];
                $failed = true;
            }
        }

        $this->table(['Tenant', 'Status', 'Fetched', 'Created', 'Updated', 'Removed', 'Unmatched'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
