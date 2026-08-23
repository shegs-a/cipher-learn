<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Tenancy;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * An authenticatable identity: anyone who logs in — admin, L&D operator, or a
 * learner who has been provisioned a login. Deliberately NOT tenant-scoped by a
 * global scope: authentication has to look users up across tenants before a
 * tenant is in context, and the logged-in user is what *establishes* the current
 * tenant (see BindCurrentTenant).
 *
 * The person a learner-User represents lives in {@see Employee} (HRIS-owned);
 * the two are linked by `employees.user_id`. Roles/permissions attach here, to
 * the User, never to the Employee.
 *
 * @property bool $is_active
 * @property bool $is_platform_admin
 * @property string|null $tenant_id
 * @property-read Employee|null $employee
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, HasRoles, HasUlids, Notifiable;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_platform_admin' => 'boolean',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The HRIS-mirrored person this login represents, if any.
     *
     * Null for pure operators (an L&D admin who is not themselves an employee);
     * set for a learner whose `User` was provisioned against their `Employee`.
     *
     * @return HasOne<Employee, $this>
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /** Permissions that grant a foothold in the admin panel — any one is enough. */
    private const ADMIN_PANEL_PERMISSIONS = [
        'courses.view', 'learning_paths.view', 'employees.view',
        'enrollments.view', 'reports.view', 'users.view', 'roles.view',
    ];

    /**
     * Permissions that make the admin panel someone's *home* after login.
     *
     * Deliberately a subset of {@see ADMIN_PANEL_PERMISSIONS}: it excludes the
     * three manager-scoped reads (`employees.view`, `enrollments.view`,
     * `reports.view`) that a plain line **Manager** also holds. A Manager can
     * still reach the panel via the portal switcher, but their *home* is the
     * learner portal, where "My Team" lives — settled in Sprint 5, resolving the
     * Sprint 4 open UX item. An operator (Content/L&D/Tenant Admin) holds at
     * least one of these and lands on the panel as before.
     */
    private const PANEL_LANDING_PERMISSIONS = [
        'courses.view', 'learning_paths.view', 'users.view', 'roles.view',
    ];

    /**
     * Who may reach the admin panel.
     *
     * Must be active and belong to a tenant, AND hold at least one admin-surface
     * permission (or be the platform super-admin). A learner-only identity has
     * none of these, so it is kept out of the admin panel and routed to the
     * learner portal instead (see the login redirect). The Gate::before bypass
     * means `can()` already returns true for a platform admin.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active || $this->tenant_id === null) {
            return false;
        }

        // Establish the tenant here, not just in BindCurrentTenant: Filament calls
        // canAccessPanel from its Authenticate middleware, which runs BEFORE
        // BindCurrentTenant. Without this the permission check below would run with
        // no team set and deny a legitimate admin (a 403 straight after login).
        app(Tenancy::class)->set($this->tenant_id);

        return $this->canAny(self::ADMIN_PANEL_PERMISSIONS);
    }

    /**
     * Whether this identity's *home* after login is the admin panel (vs the
     * learner portal). True for operators; false for a plain Manager or a
     * learner. Requires panel access first, so it also enforces active/tenant.
     * See {@see PANEL_LANDING_PERMISSIONS} for why Managers land on the portal.
     */
    public function landsOnAdminPanel(Panel $panel): bool
    {
        return $this->canAccessPanel($panel)
            && $this->canAny(self::PANEL_LANDING_PERMISSIONS);
    }
}
