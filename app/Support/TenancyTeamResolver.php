<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

/**
 * Makes "the current tenant" and "the current permission team" one and the same.
 *
 * spatie/laravel-permission scopes tenant roles by a "team id". Rather than
 * remembering to call `setPermissionsTeamId()` on every entry path — web,
 * Filament, queued jobs, console — this resolver PULLS the id straight from
 * {@see Tenancy}, the single source of truth every context already funnels
 * through (BindCurrentTenant for web/Filament, `Tenancy::runFor` for
 * console/queue/seeders). One wire, no path left unscoped.
 *
 * That inversion matters for security: the plan's warning is that "a permission
 * check with no team context set is the leak." Here there is no separate team
 * context to forget to set — if a tenant is in scope, so is its permission team.
 */
final class TenancyTeamResolver implements PermissionsTeamResolver
{
    /**
     * An explicit override, honoured when non-null. spatie (and some tests) may
     * set a team id directly; that wins. Null (the default) falls back to the
     * live tenant, which is what we want everywhere in normal operation.
     */
    private int|string|null $override = null;

    // No constructor: spatie's PermissionRegistrar instantiates this with
    // `new (...)` and no container, so we cannot inject Tenancy. We resolve the
    // (singleton) Tenancy from the container lazily instead — see below.

    public function getPermissionsTeamId(): int|string|null
    {
        return $this->override ?? app(Tenancy::class)->id();
    }

    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        $this->override = $id instanceof Model ? $id->getKey() : $id;
    }
}
