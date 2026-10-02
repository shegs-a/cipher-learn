<?php

declare(strict_types=1);

use App\Filament\Pages\AssignmentPolicyPage;
use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Filament\Resources\SyncRunResource\Pages\ListSyncRuns;
use App\Filament\Widgets\AdminOverviewStats;
use App\Jobs\SyncLeaveJob;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\Enrollment;
use App\Models\LearningPath;
use App\Models\PathEnrollment;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\FakeLeaveAdapter;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-02 12:00:00');
    FakeLeaveAdapter::register();

    $this->tenant = Tenant::factory()->create(['timezone' => 'UTC', 'hris_adapter' => 'fake-leave']);
    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    actingAs($this->admin);
    app(Tenancy::class)->set($this->tenant->id);

    app(RolesAndPermissionsSeeder::class)->run();
    $this->admin->assignRole('Tenant Admin');
});

function uiUserWithRole(Tenant $tenant, string $role): User
{
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    app(Tenancy::class)->runFor($tenant, fn () => $user->assignRole($role));

    return $user;
}

describe('assignment policy settings page', function () {
    it('is off by default', function () {
        Livewire::test(AssignmentPolicyPage::class)
            ->assertFormSet([Tenant::SETTING_ALLOW_ASSIGNMENT_ON_LEAVE => false]);

        expect($this->tenant->fresh()->allowsAssignmentDuringLeave())->toBeFalse();
    });

    it('saves the setting for this tenant and records the change in the audit trail', function () {
        Livewire::test(AssignmentPolicyPage::class)
            ->fillForm([Tenant::SETTING_ALLOW_ASSIGNMENT_ON_LEAVE => true])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->tenant->fresh()->allowsAssignmentDuringLeave())->toBeTrue();

        $entry = AuditLog::query()->where('event', 'settings.assignment_policy_changed')->sole();
        expect($entry->old_values)->toBe([Tenant::SETTING_ALLOW_ASSIGNMENT_ON_LEAVE => false])
            ->and($entry->new_values)->toBe([Tenant::SETTING_ALLOW_ASSIGNMENT_ON_LEAVE => true]);
    });

    it('does not touch other settings or other tenants', function () {
        $this->tenant->update(['settings' => ['something_else' => 'kept']]);
        $other = Tenant::factory()->create();

        Livewire::test(AssignmentPolicyPage::class)
            ->fillForm([Tenant::SETTING_ALLOW_ASSIGNMENT_ON_LEAVE => true])
            ->call('save');

        expect($this->tenant->fresh()->settings)->toMatchArray(['something_else' => 'kept', Tenant::SETTING_ALLOW_ASSIGNMENT_ON_LEAVE => true])
            ->and($other->fresh()->allowsAssignmentDuringLeave())->toBeFalse();
    });

    it('writes no audit entry when nothing changed', function () {
        Livewire::test(AssignmentPolicyPage::class)->call('save');

        expect(AuditLog::query()->where('event', 'settings.assignment_policy_changed')->count())->toBe(0);
    });

    it('is available to the tenant administrator only', function () {
        expect(AssignmentPolicyPage::canAccess())->toBeTrue();

        foreach (['L&D Manager', 'Manager', 'Content Administrator', 'Learner'] as $role) {
            actingAs(uiUserWithRole($this->tenant, $role));
            expect(AssignmentPolicyPage::canAccess())->toBeFalse("{$role} must not reach the policy page");
        }
    });

    it('refuses to save for someone without the permission', function () {
        actingAs(uiUserWithRole($this->tenant, 'Manager'));

        Livewire::test(AssignmentPolicyPage::class)->assertForbidden();
    });
});

describe('the "Sync leave now" button', function () {
    it('queues a manual sync attributed to the clicking admin', function () {
        Bus::fake();

        Livewire::test(ListEmployees::class)->callAction('syncLeave');

        Bus::assertDispatched(SyncLeaveJob::class, fn ($job) => $job->tenantId === $this->tenant->id && $job->userId === $this->admin->id);
    });

    it('records a manual run (with who and what) that shows up in the sync history', function () {
        Livewire::test(ListSyncRuns::class)->callAction('syncLeave');

        $run = SyncRun::query()->where('type', 'leave_sync')->sole();

        expect($run->trigger)->toBe('manual')
            ->and($run->triggered_by_user_id)->toBe($this->admin->id)
            ->and($run->status)->toBe('completed');

        Livewire::test(ListSyncRuns::class)->assertCanSeeTableRecords([$run]);
    });

    it('stops a second click inside the cooldown', function () {
        Bus::fake();

        Livewire::test(ListEmployees::class)->callAction('syncLeave');
        Livewire::test(ListEmployees::class)->callAction('syncLeave');

        Bus::assertDispatchedTimes(SyncLeaveJob::class, 1);
    });

    it('is only offered to people who may trigger syncs', function () {
        actingAs(uiUserWithRole($this->tenant, 'L&D Manager')); // can view employees, cannot sync

        Livewire::test(ListEmployees::class)->assertActionHidden('syncLeave');

        actingAs($this->admin);
        Livewire::test(ListEmployees::class)->assertActionVisible('syncLeave');
    });
});

describe('sync history reporting', function () {
    it('can filter to leave syncs and to manual runs', function () {
        $leave = SyncRun::factory()->create(['type' => 'leave_sync', 'trigger' => 'scheduled', 'slot' => '2026-10-02@06:00']);
        $manual = SyncRun::factory()->create(['type' => 'leave_sync', 'trigger' => 'manual']);
        $employee = SyncRun::factory()->create(['type' => 'employee_sync']);

        Livewire::test(ListSyncRuns::class)
            ->filterTable('type', 'leave_sync')
            ->assertCanSeeTableRecords([$leave, $manual])
            ->assertCanNotSeeTableRecords([$employee])
            ->filterTable('trigger', 'manual')
            ->assertCanSeeTableRecords([$manual])
            ->assertCanNotSeeTableRecords([$leave]);
    });

    it('explains a failed run on the row', function () {
        $failed = SyncRun::factory()->create(['type' => 'leave_sync', 'status' => 'failed', 'stats' => ['error' => 'The HR system timed out.']]);

        Livewire::test(ListSyncRuns::class)->assertCanSeeTableRecords([$failed]);
    });
});

describe('dashboard', function () {
    it('shows when leave data was last refreshed, and warns when it is stale', function () {
        Livewire::test(AdminOverviewStats::class)->assertSee('Leave data')->assertSee('Never synced');

        SyncRun::factory()->create(['type' => 'leave_sync', 'status' => 'completed', 'finished_at' => now()->subHours(2)]);

        Livewire::test(AdminOverviewStats::class)->assertSee('2 hours ago')->assertDontSee('Out of date');

        SyncRun::query()->update(['finished_at' => now()->subHours(20)]);

        Livewire::test(AdminOverviewStats::class)->assertSee('Out of date');
    });

    it('does not show the leave stat for an HR system without leave', function () {
        FakeLeaveAdapter::$supportsLeave = false;

        Livewire::test(AdminOverviewStats::class)->assertDontSee('Leave data');
    });
});

describe('bulk assignment from the Employees table', function () {
    beforeEach(function () {
        Notification::fake();
        $this->course = Course::factory()->create(['title' => 'Advanced Leadership', 'status' => 'published']);
        $this->free = Employee::factory()->count(2)->create();
        $this->away = Employee::factory()->create();
        EmployeeLeave::factory()->for($this->away)->create(['starts_on' => '2026-09-28', 'ends_on' => '2026-10-07']);
    });

    it('assigns the course to the selected employees and blocks those on leave', function () {
        $selected = $this->free->push($this->away);

        Livewire::test(ListEmployees::class)
            ->callTableBulkAction('assignCourse', $selected, data: [
                'course_id' => $this->course->id,
                'rationale' => 'Leadership pipeline',
            ])
            ->assertHasNoTableBulkActionErrors();

        expect(Enrollment::count())->toBe(2)
            ->and(Enrollment::where('employee_id', $this->away->id)->exists())->toBeFalse();

        $summary = AuditLog::query()->where('event', 'assignment.bulk_completed')->sole();
        expect($summary->new_values)->toMatchArray(['total' => 3, 'assigned' => 2, 'blocked' => 1, 'scope' => 'selection']);
    });

    it('assigns a learning path to the selection with the same all-or-nothing rule', function () {
        $path = LearningPath::factory()->create();
        $path->courses()->attach(Course::factory()->count(2)->create()->mapWithKeys(fn ($c, $i) => [$c->id => ['position' => $i + 1]])->all());

        Livewire::test(ListEmployees::class)
            ->callTableBulkAction('assignPath', $this->free->push($this->away), data: [
                'path_id' => $path->id,
                'rationale' => 'Pipeline',
            ])
            ->assertHasNoTableBulkActionErrors();

        expect(PathEnrollment::count())->toBe(2)
            ->and(Enrollment::count())->toBe(4)
            ->and(PathEnrollment::where('employee_id', $this->away->id)->exists())->toBeFalse();
    });

    it('is only offered to people who may assign in bulk', function () {
        actingAs(uiUserWithRole($this->tenant, 'Manager')); // enrollments.assign but not assign_org

        Livewire::test(ListEmployees::class)->assertTableBulkActionHidden('assignCourse');
    });
});
