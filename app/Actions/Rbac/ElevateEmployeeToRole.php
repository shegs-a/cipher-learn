<?php

declare(strict_types=1);

namespace App\Actions\Rbac;

use App\Models\Employee;
use App\Models\User;
use App\Support\Audit\Auditor;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Grants an employee an elevated role — the "Create LMS admin" operation.
 *
 * The person stays an {@see Employee} (HR owns that record). What this adds is a
 * login identity ({@see User}) as the auth vehicle, linked via employees.user_id,
 * and the role on that identity. Roles live on the User because that is what
 * authenticates; but the admin's mental model is "elevate this employee", so this
 * is the single entry point for both concerns.
 *
 * Idempotent per role: re-elevating with the same role is a no-op (spatie's
 * assignRole does not duplicate). An employee who already has a login keeps it —
 * only the role is added.
 *
 * MUST be called inside the employee's tenant context (Tenancy::runFor / the
 * panel), so spatie stamps the role against the right team.
 */
final class ElevateEmployeeToRole
{
    public function handle(Employee $employee, string $role, ?string $password = null): User
    {
        $user = $employee->user;

        if ($user === null) {
            if (blank($employee->email)) {
                // Without an email we have no login handle to provision.
                throw new RuntimeException('This employee has no email address, so a login cannot be created for them.');
            }

            $user = User::create([
                'tenant_id' => $employee->tenant_id,
                'name' => $employee->full_name,
                'email' => $employee->email,
                // The hashed cast on User hashes this. A blank password gets a
                // random one (the person then signs in via a future reset/SSO).
                'password' => filled($password) ? $password : Str::password(16),
                'auth_provider' => 'local',
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            $employee->update(['user_id' => $user->id]);
        }

        $user->assignRole($role);

        // Role grants are a privilege change, but they write to spatie's pivot
        // table — not an audited model — so nothing above would capture them.
        // Record it explicitly against the affected user, naming the role granted.
        app(Auditor::class)->log('rbac.role_granted', $user, newValues: ['role' => $role]);

        return $user;
    }
}
