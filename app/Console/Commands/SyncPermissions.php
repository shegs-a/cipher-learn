<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roll the permission vocabulary and role→permission grants out to every tenant.
 *
 * The problem this solves: roles are **team-scoped** (per tenant), so a release
 * that adds a new permission — e.g. Sprint 10's `audit.view` — only reaches the
 * tenants whose roles get re-synced. Running the seeder once (as the demo does)
 * touches a single tenant; a long-lived customer database would silently miss the
 * new grant, and its Tenant Admins would not see the new feature. (Exactly what the
 * owner hit manually after Sprint 10.)
 *
 * This command re-runs {@see RolesAndPermissionsSeeder} inside **each tenant's**
 * context, so the grants land on the right team every time. The seeder is
 * idempotent (findOrCreate + syncPermissions), so re-running is safe and a no-op
 * when nothing changed. Belongs in the release step, right after `migrate --force`.
 */
final class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync {--tenant= : Sync a single tenant by id, instead of all}';

    protected $description = 'Sync the permission vocabulary and role grants across every tenant';

    public function handle(Tenancy $tenancy): int
    {
        $tenants = $this->option('tenant') !== null
            ? Tenant::query()->whereKey($this->option('tenant'))->get()
            : Tenant::query()->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants to sync.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenant) {
            // Re-run the seeder in this tenant's context so team-scoped roles get
            // the current grants. The registrar caches resolved permissions, so
            // forget it between tenants to avoid a stale team's grants leaking.
            $tenancy->runFor($tenant, function (): void {
                app(PermissionRegistrar::class)->forgetCachedPermissions();
                app(RolesAndPermissionsSeeder::class)->run();
            });

            $this->line("  ✓ {$tenant->getKey()}  {$tenant->name}");
        }

        $this->info("Permissions synced across {$tenants->count()} ".str('tenant')->plural($tenants->count()).'.');

        return self::SUCCESS;
    }
}
