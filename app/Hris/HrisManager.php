<?php

declare(strict_types=1);

namespace App\Hris;

use App\Hris\Contracts\HrisEmployeeSource;
use App\Hris\Contracts\HrisLeaveSource;
use App\Hris\Contracts\HrisWriteback;
use App\Models\Tenant;
use InvalidArgumentException;

/**
 * Resolves the HRIS adapter a given tenant should use.
 *
 * This is the only place that knows how `tenants.hris_adapter` maps to a class.
 * Everything else — the sync, the console command, Sprint 6's outbox — asks the
 * manager for an adapter and works against the interfaces, so a tenant can move
 * from the mock to a real HR system by changing one column, with no code change
 * and no redeploy.
 *
 * Adapters are built per-tenant rather than registered as singletons because
 * their credentials come from `tenants.settings`, which differs per tenant. In a
 * queue worker that processes several tenants in one process, a shared instance
 * would be a cross-tenant credential leak.
 */
final class HrisManager
{
    /** @var array<string, HrisEmployeeSource&HrisWriteback> */
    private array $resolved = [];

    /**
     * The adapter for this tenant, honouring `tenants.hris_adapter`.
     *
     * Memoised per tenant id so a sync that asks repeatedly doesn't rebuild an
     * HTTP client each time — but never across tenants (see the class docblock).
     */
    public function for(Tenant $tenant): HrisEmployeeSource&HrisWriteback
    {
        $key = (string) $tenant->getKey();

        return $this->resolved[$key] ??= $this->build(
            $tenant->hris_adapter ?: $this->defaultAdapter(),
            $tenant->settings ?? [],
        );
    }

    /**
     * Build an adapter by its config key, with the tenant's settings.
     *
     * Public so tests and the console command can construct an adapter without a
     * persisted tenant.
     *
     * @param  array<string, mixed>  $settings
     */
    public function build(string $adapter, array $settings = []): HrisEmployeeSource&HrisWriteback
    {
        /** @var array<string, class-string> $registry */
        $registry = config('hris.adapters', []);

        if (! isset($registry[$adapter])) {
            // Fail loudly and name the valid options — a typo'd adapter key must
            // never silently fall back to the mock in production, which would
            // look like a working sync against entirely fabricated people.
            throw new InvalidArgumentException(sprintf(
                'Unknown HRIS adapter [%s]. Registered adapters: [%s].',
                $adapter,
                implode(', ', array_keys($registry)),
            ));
        }

        $class = $registry[$adapter];
        $instance = app($class, ['settings' => $settings]);

        if (! $instance instanceof HrisEmployeeSource || ! $instance instanceof HrisWriteback) {
            throw new InvalidArgumentException(sprintf(
                'HRIS adapter [%s] must implement both %s and %s.',
                $class,
                HrisEmployeeSource::class,
                HrisWriteback::class,
            ));
        }

        return $instance;
    }

    /**
     * The tenant's adapter as a leave source, or null when it cannot report leave.
     *
     * Leave is an optional capability, so an adapter that does not implement
     * {@see HrisLeaveSource} (or says it does not support it) simply yields null
     * and the leave sync skips that tenant.
     */
    public function leaveSourceFor(Tenant $tenant): ?HrisLeaveSource
    {
        $adapter = $this->for($tenant);

        return $adapter instanceof HrisLeaveSource && $adapter->supportsLeave() ? $adapter : null;
    }

    private function defaultAdapter(): string
    {
        /** @var string $default */
        $default = config('hris.default', 'mock');

        return $default;
    }
}
