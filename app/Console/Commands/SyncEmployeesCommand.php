<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Hris\Sync\SyncEmployees;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pull the employee directory from each tenant's HR system.
 *
 * The operational entry point to the sync — run by hand during a demo, and on a
 * schedule in production. Deliberately requires either `--tenant` or `--all`
 * rather than defaulting to every tenant: on a multi-tenant install an
 * accidental full run is a lot of avoidable HR API traffic.
 */
class SyncEmployeesCommand extends Command
{
    protected $signature = 'hris:sync-employees
                            {--tenant= : Sync one tenant, by slug or id}
                            {--all : Sync every tenant}
                            {--force : Override the mass-exit guard (for a genuine large reduction in force)}';

    protected $description = "Sync employees from each tenant's HR system into the LMS";

    public function handle(SyncEmployees $sync): int
    {
        $tenants = $this->resolveTenants();

        if ($tenants === null) {
            return self::FAILURE;
        }

        if ($tenants === []) {
            // Distinguish "nothing to do" from "you named something that doesn't
            // exist". --all on a fresh install legitimately has no tenants; a
            // typo'd --tenant must NOT report success having synced nobody, or a
            // scheduled sync can quietly do nothing for weeks.
            if ($this->option('all')) {
                $this->components->warn('No tenants exist yet — nothing to sync.');

                return self::SUCCESS;
            }

            $this->components->error("No tenant matched [{$this->option('tenant')}].");

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');

        $rows = [];
        $failed = false;

        /** @var list<string> $guardTrips tenants whose mass-exit guard tripped */
        $guardTrips = [];

        foreach ($tenants as $tenant) {
            $this->components->task(
                "Syncing {$tenant->name}",
                function () use ($sync, $tenant, $force, &$rows, &$failed, &$guardTrips): bool {
                    try {
                        $run = $sync->forTenant($tenant, $force);
                        $stats = $run->stats ?? [];

                        $rows[] = [
                            $tenant->name,
                            $stats['adapter'] ?? '—',
                            $stats['created'] ?? 0,
                            $stats['updated'] ?? 0,
                            $stats['unchanged'] ?? 0,
                            $stats['exited'] ?? 0,
                            $stats['errors'] ?? 0,
                        ];

                        // A tripped guard is a failure outcome for this run: the
                        // sweep was withheld and needs a human. Flag it and exit
                        // non-zero, but don't stop the other tenants.
                        if (($stats['exit_guard_tripped'] ?? false) === true) {
                            $guardTrips[] = sprintf(
                                '%s: %d of %d active employees (%.0f%%) would be exited — sweep withheld.',
                                $tenant->name,
                                $stats['would_exit'] ?? 0,
                                $stats['active_total'] ?? 0,
                                (($stats['exit_fraction'] ?? 0) * 100),
                            );
                            $failed = true;

                            return false;
                        }

                        return true;
                    } catch (Throwable $e) {
                        // Keep going: one tenant's HR system being down must not
                        // stop the others from syncing. The run is already
                        // recorded as failed on its SyncRun row.
                        $rows[] = [$tenant->name, 'failed', 0, 0, 0, 0, $e->getMessage()];
                        $failed = true;

                        return false;
                    }
                }
            );
        }

        $this->newLine();
        $this->table(
            ['Tenant', 'Adapter', 'Created', 'Updated', 'Unchanged', 'Exited', 'Errors'],
            $rows,
        );

        foreach ($guardTrips as $message) {
            $this->components->warn($message);
        }

        if ($guardTrips !== []) {
            $this->components->info('Re-run with --force once the HR feed is confirmed healthy, or to apply a genuine reduction in force.');
        }

        // Non-zero exit so a scheduler or CI job notices a partial failure.
        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<Tenant>|null null signals a usage error (already reported)
     */
    private function resolveTenants(): ?array
    {
        $identifier = $this->option('tenant');

        if ($this->option('all') && $identifier !== null) {
            $this->components->error('Use either --tenant or --all, not both.');

            return null;
        }

        if ($this->option('all')) {
            return Tenant::query()->orderBy('name')->get()->all();
        }

        if ($identifier === null) {
            $this->components->error('Specify --tenant=<slug|id>, or --all to sync every tenant.');

            return null;
        }

        return Tenant::query()
            ->where('slug', $identifier)
            ->orWhere('id', $identifier)
            ->get()
            ->all();
    }
}
