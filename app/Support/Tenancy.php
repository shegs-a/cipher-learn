<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use Closure;

/**
 * Holds the current tenant for the lifetime of a request (or a job, or a
 * `runFor` block). The BelongsToTenant global scope reads from here, which is
 * what lets every business query be tenant-filtered without each call site
 * remembering to add `where tenant_id = ...`.
 *
 * Resolution of the current tenant is deliberately explicit:
 *  - web/Filament: set from the authenticated user (see BindCurrentTenant).
 *  - console/queue/seeders/tests: wrap work in `Tenancy::runFor($tenant, ...)`.
 *
 * When no tenant is set (a genuine system context) the scope does not constrain
 * queries — so cross-tenant maintenance is possible, but only on purpose, never
 * by forgetting.
 */
final class Tenancy
{
    // Tenant keys are ULID strings (see the migrations), so the stored id is a
    // string in practice; int is kept in the union only for defensiveness.
    private int|string|null $tenantId = null;

    public function id(): int|string|null
    {
        return $this->tenantId;
    }

    public function has(): bool
    {
        return $this->tenantId !== null;
    }

    public function set(Tenant|int|string|null $tenant): void
    {
        $this->tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;
    }

    public function forget(): void
    {
        $this->tenantId = null;
    }

    /**
     * Run a callback with a specific tenant in context, restoring the previous
     * context afterwards. Used by seeders, jobs and tests.
     *
     * @template T
     *
     * @param  Closure():T  $callback
     * @return T
     */
    public function runFor(Tenant|int|string $tenant, Closure $callback): mixed
    {
        $previous = $this->tenantId;
        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $this->tenantId = $previous;
        }
    }
}
