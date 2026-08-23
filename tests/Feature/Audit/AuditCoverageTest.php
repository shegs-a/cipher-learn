<?php

declare(strict_types=1);

use App\Actions\Rbac\ElevateEmployeeToRole;
use App\Livewire\Auth\Login;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Covers that the audit trail actually *captures* the domain — both the automatic
 * model auditing (via the Auditable trait) and the explicit events wired at the
 * places no single model write would record (auth, exports, role grants).
 */
beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(Tenancy::class)->set($this->tenant->id);
});

/** All audit rows for the current tenant, newest first, ignoring global scopes. */
function auditEvents(string $tenantId): array
{
    return AuditLog::withoutGlobalScopes()
        ->where('tenant_id', $tenantId)
        ->orderByDesc('sequence')
        ->pluck('event')
        ->all();
}

it('auto-audits model create, update and delete via the Auditable trait', function () {
    app(Tenancy::class)->runFor($this->tenant, function () {
        $course = Course::factory()->create(['title' => 'Original']);
        $course->update(['title' => 'Renamed']);
        $course->delete();

        $entries = AuditLog::withoutGlobalScopes()
            ->where('auditable_type', Course::class)
            ->where('auditable_id', $course->id)
            ->orderBy('sequence')
            ->get();

        expect($entries->pluck('event')->all())
            ->toBe(['course.created', 'course.updated', 'course.deleted']);

        // The update entry carries the before/after of just what changed.
        $updated = $entries->firstWhere('event', 'course.updated');
        expect($updated->old_values['title'])->toBe('Original')
            ->and($updated->new_values['title'])->toBe('Renamed');
    });
});

it('does not write an update entry when nothing actually changed', function () {
    app(Tenancy::class)->runFor($this->tenant, function () {
        $course = Course::factory()->create(['title' => 'Same']);
        $before = AuditLog::withoutGlobalScopes()->count();

        $course->update(['title' => 'Same']); // no-op

        expect(AuditLog::withoutGlobalScopes()->count())->toBe($before);
    });
});

it('records a successful login on the user tenant chain', function () {
    $user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'password' => 'password123',
        'is_active' => true,
    ]);

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password123')
        ->call('authenticate');

    expect(auditEvents($this->tenant->id))->toContain('auth.login');
});

it('records a failed login on the platform (null-tenant) chain, without the password', function () {
    User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'email' => 'real@example.com',
        'password' => 'password123',
    ]);

    // A real /login request arrives with no tenant established, so the failed
    // attempt lands on the platform chain. Clear the context the setup set up.
    app(Tenancy::class)->forget();

    Livewire::test(Login::class)
        ->set('email', 'real@example.com')
        ->set('password', 'wrong-password')
        ->call('authenticate')
        ->assertHasErrors('email');

    $failed = AuditLog::withoutGlobalScopes()
        ->whereNull('tenant_id')
        ->where('event', 'auth.login_failed')
        ->first();

    expect($failed)->not->toBeNull()
        ->and($failed->context['email'])->toBe('real@example.com')
        // The password must never appear anywhere in the recorded row.
        ->and(json_encode($failed->context))->not->toContain('wrong-password');
});

it('records a role grant as an explicit rbac event', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    app(Tenancy::class)->runFor($this->tenant, function () {
        $employee = Employee::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email' => 'grantee@example.com',
        ]);

        app(ElevateEmployeeToRole::class)->handle($employee, 'Content Administrator');

        expect(auditEvents($this->tenant->id))->toContain('rbac.role_granted');

        $grant = AuditLog::withoutGlobalScopes()
            ->where('event', 'rbac.role_granted')
            ->first();
        expect($grant->new_values['role'])->toBe('Content Administrator');
    });
});
