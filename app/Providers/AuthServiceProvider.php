<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Platform super-admin bypass. A cross-tenant operator (us, running the
        // platform) is modelled as a boolean on `users`, NOT a spatie role —
        // team-scoped roles grant nothing outside their tenant, and this must
        // never be assignable through the tenant role UI.
        //
        // Returning true short-circuits every gate/permission check; returning
        // null (for everyone else) lets the normal checks run. It must be null,
        // not false, or it would DENY everything for non-platform users.
        Gate::before(function (?User $user): ?bool {
            return $user?->is_platform_admin ? true : null;
        });

        // Who may assign a course TO a given employee.
        //  - org-wide assigners (HR / L&D / Tenant Admin) → anyone in the tenant;
        //  - individual assigners (a line manager) → only their DIRECT reports;
        //  - anyone else → no.
        // `enrollments.assign` alone is the line-manager capability; the
        // relationship check is what keeps a manager to their own team.
        Gate::define('assign-course-to', function (User $user, Employee $target): bool {
            if ($user->can('enrollments.assign_org')) {
                return true;
            }

            if (! $user->can('enrollments.assign')) {
                return false;
            }

            return $target->manager_id !== null
                && $user->employee?->id !== null
                && $target->manager_id === $user->employee->id;
        });
    }
}
