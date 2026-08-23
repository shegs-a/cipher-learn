<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the permission vocabulary (global) and the starter roles (per tenant).
 *
 * MUST be called inside a tenant context (`Tenancy::runFor`): roles are
 * team-scoped, so the current tenant — resolved by TenancyTeamResolver — is
 * stamped onto each role as it is created. Permissions are global (the roles
 * table carries the team key, the permissions table does not), so they are
 * created once and shared.
 *
 * The role→permission grants below are the security policy of the whole app in
 * one place. The acid test: a Content Administrator can build courses and paths
 * and see NOTHING about who took them, who failed, or who anyone is.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /** The full permission vocabulary the app checks against. */
    public const PERMISSIONS = [
        'courses.view', 'courses.manage',
        'learning_paths.view', 'learning_paths.manage',
        'employees.view',      // the synced workforce ("who anyone is")
        'enrollments.view',    // who is assigned / took / failed what
        'enrollments.assign',      // assign a course to an individual (Sprint 4)
        'enrollments.assign_org',  // assign a course org-wide / in bulk (Sprint 4)
        'reports.view',        // learning-activity reporting
        'users.view', 'users.manage',   // login identities (not employees)
        'roles.view', 'roles.assign', 'roles.manage',
        'rules.manage',        // competency rules — consumed Sprint 4
        'audit.view',          // read the tamper-evident audit trail (Sprint 10)
    ];

    /**
     * Role → the permissions it holds. Absence is the point: Content
     * Administrator's list is deliberately short.
     *
     * @var array<string, list<string>>
     */
    public const ROLES = [
        // Everything in-tenant. Filled from PERMISSIONS at seed time.
        'Tenant Admin' => ['*'],

        'L&D Manager' => [
            'courses.view', 'courses.manage',
            'learning_paths.view', 'learning_paths.manage',
            'employees.view', 'enrollments.view',
            'enrollments.assign', 'enrollments.assign_org', // L&D assigns anyone / org-wide
            'reports.view',
            'rules.manage',
            // deliberately NOT users.manage / roles.assign
        ],

        // The acid-test role: authoring only, zero visibility into people or
        // activity. If this list ever grows an enrollments/reports/users/roles
        // permission, RBAC is broken.
        'Content Administrator' => [
            'courses.view', 'courses.manage',
            'learning_paths.view', 'learning_paths.manage',
        ],

        // Sees and assigns to their own reports (the "own reports" scoping is
        // applied in the query/policy layer, not by the permission itself). A
        // line manager can assign to individuals but NOT org-wide.
        'Manager' => [
            'employees.view', 'enrollments.view', 'enrollments.assign', 'reports.view',
        ],

        // Own learning only — no admin permissions at all. Exists as a named
        // role so portal routing and assignment have something to attach to.
        'Learner' => [],
    ];

    public function run(): void
    {
        // Global permission set (idempotent). Guard 'web' — the single web guard.
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Roles for the current tenant (team id comes from Tenancy).
        foreach (self::ROLES as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');

            $grants = $permissions === ['*'] ? self::PERMISSIONS : $permissions;
            $role->syncPermissions($grants);
        }

        // The registrar caches resolved permissions; forget it so a follow-up
        // check in the same process sees what we just seeded.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
