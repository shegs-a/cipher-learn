<?php

declare(strict_types=1);

use App\Actions\Assignment\AssignCourse;
use App\Actions\Assignment\AssignCourseOrgWide;
use App\Actions\Assignment\AssignCourseToEmployees;
use App\Assignment\AssignmentBlockedException;
use App\Enums\AssignmentBlockReason;
use App\Enums\AssignmentOutcome;
use App\Enums\AssignmentSkipReason;
use App\Enums\EmployeeStatus;
use App\Enums\EnrollmentSource;
use App\Enums\EnrollmentStatus;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\Enrollment;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AssignmentBlockedNotification;
use App\Notifications\BulkAssignmentBlockedNotification;
use App\Notifications\CourseAssignedNotification;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeLeaveAdapter;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-02 12:00:00');
    FakeLeaveAdapter::register();

    $this->tenant = Tenant::factory()->create(['timezone' => 'UTC']);
    app(Tenancy::class)->set($this->tenant->id);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Ada Admin']);
    $this->course = Course::factory()->create(['title' => 'Advanced Leadership']);

    Notification::fake();
});

/** An employee with a login, so their notifications are observable. */
function learnerEmployee(array $attrs = []): Employee
{
    $user = User::factory()->create(['tenant_id' => app(Tenancy::class)->id()]);

    return Employee::factory()->create($attrs + ['user_id' => $user->id]);
}

/** Put an employee on leave over [from, to] (Y-m-d), relative-friendly via strtotime strings. */
function putOnLeave(Employee $employee, string $from = '2026-09-28', string $to = '2026-10-07', ?bool $current = null): EmployeeLeave
{
    return EmployeeLeave::factory()->for($employee)->create([
        'starts_on' => $from,
        'ends_on' => $to,
        'is_current' => $current,
    ]);
}

function allowLeaveAssignments(Tenant $tenant, bool $allow = true): void
{
    $tenant->update(['settings' => [Tenant::SETTING_ALLOW_ASSIGNMENT_ON_LEAVE => $allow]]);
}

function assignCourse(Employee $employee, Course $course, ?User $by = null)
{
    return app(AssignCourse::class)->attempt($employee, $course, 'Role development', assignedBy: $by);
}

function auditEvents(string $event)
{
    return AuditLog::query()->where('event', $event)->get();
}

describe('individual course assignment', function () {
    it('blocks assigning to an employee on leave when the policy is disabled (the default)', function () {
        $employee = learnerEmployee(['first_name' => 'Jane', 'last_name' => 'Doe']);
        putOnLeave($employee);

        $result = assignCourse($employee, $this->course, $this->admin);

        expect($result->outcome)->toBe(AssignmentOutcome::Blocked)
            ->and($result->blockReason)->toBe(AssignmentBlockReason::EmployeeOnLeave)
            ->and($result->leaveStart)->toBe('2026-09-28')
            ->and($result->leaveEnd)->toBe('2026-10-07')
            ->and(Enrollment::count())->toBe(0);
    });

    it('does not notify the employee about a blocked assignment', function () {
        $employee = learnerEmployee();
        putOnLeave($employee);

        assignCourse($employee, $this->course, $this->admin);

        Notification::assertNotSentTo($employee->user, CourseAssignedNotification::class);
        Notification::assertNothingSentTo($employee->user);
    });

    it('tells the initiating admin, with the employee, course, leave dates and the reason', function () {
        $employee = learnerEmployee(['first_name' => 'Jane', 'last_name' => 'Doe']);
        putOnLeave($employee);

        $result = assignCourse($employee, $this->course, $this->admin);

        Notification::assertSentTo($this->admin, AssignmentBlockedNotification::class, function ($n) {
            return $n->employeeName === 'Jane Doe'
                && $n->subjectName === 'Advanced Leadership'
                && $n->assignmentType === 'course'
                && $n->leavePeriod === '28 Sep 2026 – 7 Oct 2026';
        });

        $payload = (new AssignmentBlockedNotification('Jane Doe', 'course', 'Advanced Leadership', '28 Sep 2026 – 7 Oct 2026'))->toArray($this->admin);
        expect($payload['body'])->toContain('policy does not allow assignments to employees on leave')
            ->and($payload['reason'])->toBe('employee_on_leave')
            ->and($result->message('Advanced Leadership'))->toContain('Jane Doe is on leave (28 Sep 2026 – 7 Oct 2026)');
    });

    it('records a machine-readable audit entry for the blocked attempt', function () {
        $employee = learnerEmployee();
        putOnLeave($employee);

        assignCourse($employee, $this->course, $this->admin);

        $entry = auditEvents('assignment.blocked')->sole();

        expect($entry->tenant_id)->toBe($this->tenant->id)
            ->and($entry->created_at)->not->toBeNull()
            ->and($entry->auditable_id)->toBe($employee->id)
            ->and($entry->new_values)->toMatchArray([
                'block_reason' => 'employee_on_leave',
                'subject_type' => 'course',
                'subject_id' => $this->course->id,
                'employee_id' => $employee->id,
                'leave_start' => '2026-09-28',
                'leave_end' => '2026-10-07',
                'policy_allows_assignment_on_leave' => false,
                'initiated_by_user_id' => $this->admin->id,
            ]);
    });

    it('assigns normally when the policy is enabled, and marks it as made while on leave', function () {
        allowLeaveAssignments($this->tenant);
        $employee = learnerEmployee();
        putOnLeave($employee);

        $result = assignCourse($employee, $this->course, $this->admin);

        expect($result->outcome)->toBe(AssignmentOutcome::Assigned)
            ->and($result->assignedWhileOnLeave)->toBeTrue()
            ->and($result->enrollment->status)->toBe(EnrollmentStatus::Assigned)
            ->and($result->enrollment->evidence['assigned_during_leave'])->toBe(['start' => '2026-09-28', 'end' => '2026-10-07']);

        // Existing behaviour is unchanged: the learner is still notified.
        Notification::assertSentTo($employee->user, CourseAssignedNotification::class);
        Notification::assertNotSentTo($this->admin, AssignmentBlockedNotification::class);
    });

    it('makes an allowed-while-on-leave assignment distinguishable in the audit trail', function () {
        allowLeaveAssignments($this->tenant);
        $employee = learnerEmployee();
        putOnLeave($employee);

        assignCourse($employee, $this->course, $this->admin);

        $entry = auditEvents('assignment.allowed_on_leave')->sole();

        expect($entry->new_values)->toMatchArray([
            'leave_start' => '2026-09-28',
            'leave_end' => '2026-10-07',
            'policy_allows_assignment_on_leave' => true,
            'block_reason' => null,
            'initiated_by_user_id' => $this->admin->id,
        ])->and(auditEvents('assignment.blocked'))->toHaveCount(0);
    });

    it('leaves existing behaviour untouched for an employee who is not on leave', function () {
        $employee = learnerEmployee();

        $result = assignCourse($employee, $this->course, $this->admin);

        expect($result->isAssigned())->toBeTrue()
            ->and($result->assignedWhileOnLeave)->toBeFalse()
            ->and($result->enrollment->evidence)->not->toHaveKey('assigned_during_leave')
            ->and(auditEvents('assignment.blocked'))->toHaveCount(0)
            ->and(auditEvents('assignment.allowed_on_leave'))->toHaveCount(0);

        Notification::assertSentTo($employee->user, CourseAssignedNotification::class);
        Notification::assertNotSentTo($this->admin, AssignmentBlockedNotification::class);
    });

    it('is on leave from the first day through the last day, inclusive', function (string $today, bool $blocked) {
        $this->travelTo("{$today} 12:00:00");
        $employee = learnerEmployee();
        putOnLeave($employee, '2026-10-05', '2026-10-09');

        expect(assignCourse($employee, $this->course, $this->admin)->isBlocked())->toBe($blocked);
    })->with([
        'the day before it starts' => ['2026-10-04', false],
        'its first day' => ['2026-10-05', true],
        'mid-leave' => ['2026-10-07', true],
        'its last day' => ['2026-10-09', true],
        'the day after it ends' => ['2026-10-10', false],
    ]);

    it('ignores leave that is entirely in the past or the future', function () {
        $employee = learnerEmployee();
        putOnLeave($employee, '2026-08-01', '2026-08-10');
        putOnLeave($employee, '2026-11-01', '2026-11-10');

        expect(assignCourse($employee, $this->course, $this->admin)->isAssigned())->toBeTrue();
    });

    it('throws from the strict handle() entry point instead of silently carrying on', function () {
        $employee = learnerEmployee();
        putOnLeave($employee);

        expect(fn () => app(AssignCourse::class)->handle($employee, $this->course, 'Role development', assignedBy: $this->admin))
            ->toThrow(AssignmentBlockedException::class, 'is on leave');

        expect(Enrollment::count())->toBe(0);
    });

    it('reports an employee who already has the course as skipped, never blocked', function () {
        $employee = learnerEmployee();
        assignCourse($employee, $this->course, $this->admin);
        putOnLeave($employee); // goes on leave AFTER being assigned

        $result = assignCourse($employee, $this->course, $this->admin);

        expect($result->outcome)->toBe(AssignmentOutcome::Skipped)
            ->and($result->skipReason)->toBe(AssignmentSkipReason::AlreadyAssigned)
            ->and(Enrollment::count())->toBe(1);

        Notification::assertNotSentTo($this->admin, AssignmentBlockedNotification::class);
    });

    it('reports a finished enrolment as skipped for its terminal status', function () {
        $employee = learnerEmployee();
        Enrollment::factory()->for($employee)->for($this->course)->create(['status' => EnrollmentStatus::Completed, 'cycle' => 'initial']);
        putOnLeave($employee);

        $result = assignCourse($employee, $this->course, $this->admin);

        expect($result->skipReason)->toBe(AssignmentSkipReason::TerminalStatus);
    });
});

describe('learner requests and approvals', function () {
    it('never gates a learner\'s own request', function () {
        $employee = learnerEmployee();
        putOnLeave($employee);

        $request = app(AssignCourse::class)->request($employee, $this->course, 'I would like this.');

        expect($request->status)->toBe(EnrollmentStatus::Requested);
    });

    it('does gate the approval, because approving creates the assignment — and leaves the request pending', function () {
        $employee = learnerEmployee();
        app(AssignCourse::class)->request($employee, $this->course, 'I would like this.');
        putOnLeave($employee);

        $result = assignCourse($employee, $this->course, $this->admin);

        expect($result->isBlocked())->toBeTrue()
            ->and(Enrollment::sole()->status)->toBe(EnrollmentStatus::Requested);

        Notification::assertNotSentTo($employee->user, CourseAssignedNotification::class);
    });

    it('lets the approval through once the employee is back', function () {
        $employee = learnerEmployee();
        app(AssignCourse::class)->request($employee, $this->course, 'I would like this.');
        putOnLeave($employee, '2026-09-20', '2026-10-01'); // ended yesterday

        $result = assignCourse($employee, $this->course, $this->admin);

        expect($result->isAssigned())->toBeTrue()
            ->and(Enrollment::sole()->status)->toBe(EnrollmentStatus::Assigned);
    });
});

describe('bulk course assignment', function () {
    it('does not fail the batch because some employees are on leave, and reports each outcome separately', function () {
        $free = collect(range(1, 3))->map(fn () => learnerEmployee());
        $onLeave = collect(range(1, 2))->map(fn ($i) => learnerEmployee(['first_name' => "Leaver{$i}"]));
        $onLeave->each(fn ($e) => putOnLeave($e));
        $already = learnerEmployee();
        assignCourse($already, $this->course, $this->admin);
        $exited = learnerEmployee(['status' => EmployeeStatus::Exited]);

        $everyone = $free->concat($onLeave)->push($already)->push($exited);

        $result = app(AssignCourseToEmployees::class)->handle($this->course, $everyone, 'Mandatory refresher', assignedBy: $this->admin);

        expect($result->total())->toBe(7)
            ->and($result->assigned())->toBe(3)
            ->and($result->blocked())->toBe(2)
            ->and($result->skipped())->toBe(2)
            ->and($result->skippedAlreadyAssigned())->toBe(1)
            ->and($result->skippedOther())->toBe(1)
            ->and($result->summary())->toBe('3 assigned · 2 blocked — on leave · 1 skipped — already assigned · 1 skipped — other')
            ->and(Enrollment::count())->toBe(4); // 3 new + the pre-existing one
    });

    it('exposes who was blocked, why, and for how long', function () {
        $jane = learnerEmployee(['first_name' => 'Jane', 'last_name' => 'Doe']);
        putOnLeave($jane, '2026-09-28', '2026-10-07');

        $result = app(AssignCourseToEmployees::class)->handle($this->course, [$jane], 'Refresher', assignedBy: $this->admin);

        expect($result->blockedEmployees())->toBe([[
            'employee_id' => $jane->id,
            'employee' => 'Jane Doe',
            'outcome' => 'blocked',
            'reason' => 'employee_on_leave',
            'leave_start' => '2026-09-28',
            'leave_end' => '2026-10-07',
            'leave_type' => 'Annual',
        ]]);
    });

    it('sends the admin ONE summary rather than a notification per blocked employee', function () {
        collect(range(1, 5))->each(fn () => putOnLeave(learnerEmployee()));
        learnerEmployee();

        $result = app(AssignCourseOrgWide::class)->handle($this->course, 'Mandatory', assignedBy: $this->admin);

        Notification::assertSentToTimes($this->admin, BulkAssignmentBlockedNotification::class, 1);
        Notification::assertNotSentTo($this->admin, AssignmentBlockedNotification::class);
        Notification::assertSentTo($this->admin, BulkAssignmentBlockedNotification::class, fn ($n) => $n->blocked === 5
            && $n->total === 6
            && $n->assigned === 1
            && count($n->blockedNames) === 5);

        expect($result->blocked())->toBe(5);
    });

    it('sends no summary when nobody was blocked', function () {
        learnerEmployee();
        learnerEmployee();

        app(AssignCourseOrgWide::class)->handle($this->course, 'Mandatory', assignedBy: $this->admin);

        Notification::assertNotSentTo($this->admin, BulkAssignmentBlockedNotification::class);
    });

    it('keeps the detail in the audit trail: one row per blocked employee plus one summary row', function () {
        $a = learnerEmployee();
        $b = learnerEmployee();
        putOnLeave($a);
        putOnLeave($b);
        learnerEmployee();

        app(AssignCourseOrgWide::class)->handle($this->course, 'Mandatory', assignedBy: $this->admin);

        $summary = auditEvents('assignment.bulk_completed')->sole();

        expect(auditEvents('assignment.blocked'))->toHaveCount(2)
            ->and($summary->new_values)->toMatchArray(['total' => 3, 'assigned' => 1, 'blocked' => 2, 'scope' => 'org-wide'])
            ->and(collect($summary->new_values['blocked_employees'])->pluck('employee_id')->sort()->values()->all())
            ->toBe(collect([$a->id, $b->id])->sort()->values()->all());
    });

    it('notifies assigned learners as before and blocked ones not at all', function () {
        $free = learnerEmployee();
        $away = learnerEmployee();
        putOnLeave($away);

        app(AssignCourseOrgWide::class)->handle($this->course, 'Mandatory', assignedBy: $this->admin);

        Notification::assertSentTo($free->user, CourseAssignedNotification::class);
        Notification::assertNothingSentTo($away->user);
    });

    it('assigns everyone, leave or not, when the policy allows it, and counts those on leave', function () {
        allowLeaveAssignments($this->tenant);
        putOnLeave(learnerEmployee());
        learnerEmployee();

        $result = app(AssignCourseOrgWide::class)->handle($this->course, 'Mandatory', assignedBy: $this->admin);

        expect($result->assigned())->toBe(2)
            ->and($result->blocked())->toBe(0)
            ->and($result->assignedWhileOnLeave())->toBe(1);

        Notification::assertNotSentTo($this->admin, BulkAssignmentBlockedNotification::class);
    });

    it('applies the policy to a department assignment', function () {
        $financeAway = learnerEmployee(['department' => 'Finance']);
        $financeFree = learnerEmployee(['department' => 'Finance']);
        $salesFree = learnerEmployee(['department' => 'Sales']);
        putOnLeave($financeAway);

        $result = app(AssignCourseOrgWide::class)->handle($this->course, 'AML refresher', department: 'Finance', assignedBy: $this->admin);

        expect($result->total())->toBe(2)
            ->and($result->assigned())->toBe(1)
            ->and($result->blocked())->toBe(1)
            ->and(Enrollment::where('employee_id', $salesFree->id)->exists())->toBeFalse()
            ->and(Enrollment::where('employee_id', $financeFree->id)->exists())->toBeTrue()
            ->and(Enrollment::first()->evidence['scope'])->toBe('department:Finance');
    });

    it('is safe to re-run: the second run skips everyone already assigned and blocks nobody new', function () {
        putOnLeave(learnerEmployee());
        learnerEmployee();
        learnerEmployee();

        app(AssignCourseOrgWide::class)->handle($this->course, 'First', assignedBy: $this->admin);
        $second = app(AssignCourseOrgWide::class)->handle($this->course, 'Again', assignedBy: $this->admin);

        expect($second->assigned())->toBe(0)
            ->and($second->skippedAlreadyAssigned())->toBe(2)
            ->and($second->blocked())->toBe(1) // the one still on leave, never assigned
            ->and(Enrollment::count())->toBe(2);
    });

    it('carries on when one employee\'s assignment throws', function () {
        $good = learnerEmployee();
        $broken = learnerEmployee();
        $alsoGood = learnerEmployee();

        // Force a failure for one employee only.
        Enrollment::creating(function (Enrollment $e) use ($broken) {
            if ($e->employee_id === $broken->id) {
                throw new RuntimeException('boom');
            }
        });

        $result = app(AssignCourseToEmployees::class)->handle($this->course, [$good, $broken, $alsoGood], 'Refresher', assignedBy: $this->admin);

        expect($result->assigned())->toBe(2)
            ->and($result->skippedOther())->toBe(1);
    });
});

describe('the policy in different situations', function () {
    it('is evaluated per tenant: one tenant\'s setting and leave data never affect another', function () {
        $other = Tenant::factory()->create(['timezone' => 'UTC']);
        allowLeaveAssignments($other); // the OTHER tenant allows it; ours does not

        $ours = learnerEmployee();
        putOnLeave($ours);

        $theirs = app(Tenancy::class)->runFor($other, function () {
            $employee = Employee::factory()->create();
            EmployeeLeave::factory()->for($employee)->create(['starts_on' => '2026-09-28', 'ends_on' => '2026-10-07']);

            return $employee;
        });

        $theirCourse = app(Tenancy::class)->runFor($other, fn () => Course::factory()->create());

        $oursResult = assignCourse($ours, $this->course, $this->admin);
        $theirsResult = app(Tenancy::class)->runFor($other, fn () => assignCourse($theirs, $theirCourse));

        expect($oursResult->isBlocked())->toBeTrue()
            ->and($theirsResult->isAssigned())->toBeTrue()
            ->and($theirsResult->assignedWhileOnLeave)->toBeTrue();
    });

    it('does not let leave in one tenant block an employee in another', function () {
        $other = Tenant::factory()->create(['timezone' => 'UTC']);
        app(Tenancy::class)->runFor($other, fn () => EmployeeLeave::factory()->for(Employee::factory()->create())->create());

        $employee = learnerEmployee();

        expect(assignCourse($employee, $this->course, $this->admin)->isAssigned())->toBeTrue();
    });

    it('evaluates "today" in the tenant\'s timezone, not the server\'s', function () {
        // 12:00 UTC on 2 Oct is already 01:00 on 3 Oct in Auckland (UTC+13).
        $this->tenant->update(['timezone' => 'Pacific/Auckland']);
        $employee = learnerEmployee();
        putOnLeave($employee, '2026-10-03', '2026-10-10'); // starts "tomorrow" in UTC, today in Auckland

        expect(assignCourse($employee, $this->course, $this->admin)->isBlocked())->toBeTrue();

        $this->tenant->update(['timezone' => 'UTC']);
        $other = learnerEmployee();
        putOnLeave($other, '2026-10-03', '2026-10-10');

        expect(assignCourse($other, $this->course, $this->admin)->isAssigned())->toBeTrue();
    });

    it('never calls the HR system when assigning — even when it is down', function () {
        $tenant = Tenant::factory()->create(['timezone' => 'UTC', 'hris_adapter' => 'fake-leave']);
        FakeLeaveAdapter::$fails = true;

        app(Tenancy::class)->runFor($tenant, function () {
            $employee = Employee::factory()->create();
            EmployeeLeave::factory()->for($employee)->create(['starts_on' => '2026-09-28', 'ends_on' => '2026-10-07']);
            $free = Employee::factory()->create();
            $course = Course::factory()->create();

            expect(assignCourse($employee, $course)->isBlocked())->toBeTrue()
                ->and(assignCourse($free, $course)->isAssigned())->toBeTrue();
        });

        expect(FakeLeaveAdapter::$calls)->toBe(0);
    });

    describe('freshness of the leave data (fail open)', function () {
        beforeEach(function () {
            $this->hrTenant = Tenant::factory()->create(['timezone' => 'UTC', 'hris_adapter' => 'fake-leave']);
        });

        it('assigns anyway when the leave data is stale, and says so', function () {
            $result = app(Tenancy::class)->runFor($this->hrTenant, function () {
                // No leave sync has ever completed for this tenant.
                return assignCourse(Employee::factory()->create(), Course::factory()->create());
            });

            expect($result->isAssigned())->toBeTrue()
                ->and($result->leaveDataStale)->toBeTrue();
        });

        it('records the staleness on the audit entry when it blocks', function () {
            app(Tenancy::class)->runFor($this->hrTenant, function () {
                $employee = Employee::factory()->create();
                EmployeeLeave::factory()->for($employee)->create(['starts_on' => '2026-09-28', 'ends_on' => '2026-10-07']);
                assignCourse($employee, Course::factory()->create());
            });

            $entry = AuditLog::query()->withoutGlobalScopes()->where('event', 'assignment.blocked')->sole();

            expect($entry->new_values['leave_data_stale'])->toBeTrue();
        });

        it('considers fresh data fresh, and data older than the threshold stale', function () {
            app(Tenancy::class)->runFor($this->hrTenant, function () {
                $run = App\Models\SyncRun::factory()->create(['type' => 'leave_sync', 'status' => 'completed', 'finished_at' => now()->subHours(12)]);
                $employee = Employee::factory()->create();

                expect(assignCourse($employee, Course::factory()->create())->leaveDataStale)->toBeFalse();

                $run->update(['finished_at' => now()->subHours(14)]);
                $other = Employee::factory()->create();

                expect(assignCourse($other, Course::factory()->create())->leaveDataStale)->toBeTrue();
            });
        });

        it('is not "stale" for an HR system that has no leave feed at all', function () {
            FakeLeaveAdapter::$supportsLeave = false;

            $result = app(Tenancy::class)->runFor($this->hrTenant, fn () => assignCourse(Employee::factory()->create(), Course::factory()->create()));

            expect($result->leaveDataStale)->toBeFalse();
        });
    });

    describe('the HR system\'s own verdict', function () {
        it('trusts a verdict received today over the dates (early return)', function () {
            $employee = learnerEmployee();
            EmployeeLeave::factory()->for($employee)->create([
                'starts_on' => '2026-09-28', 'ends_on' => '2026-10-07',
                'is_current' => false, 'synced_at' => now(),
            ]);

            expect(assignCourse($employee, $this->course, $this->admin)->isAssigned())->toBeTrue();
        });

        it('trusts a verdict received today over the dates (extended leave)', function () {
            $employee = learnerEmployee();
            EmployeeLeave::factory()->for($employee)->create([
                'starts_on' => '2026-09-20', 'ends_on' => '2026-10-01', // dates say it ended
                'is_current' => true, 'synced_at' => now(),
            ]);

            expect(assignCourse($employee, $this->course, $this->admin)->isBlocked())->toBeTrue();
        });

        it('ignores a verdict from an earlier day and falls back to the dates', function () {
            $employee = learnerEmployee();
            EmployeeLeave::factory()->for($employee)->create([
                'starts_on' => '2026-09-28', 'ends_on' => '2026-10-07',
                'is_current' => false, 'synced_at' => now()->subDay(),
            ]);

            expect(assignCourse($employee, $this->course, $this->admin)->isBlocked())->toBeTrue();
        });
    });
});

it('only ever attributes the policy to assignments an admin or manager initiates (self-enrolment path is separate)', function () {
    // A learner's direct course request is covered above; this pins the source rule:
    // a SelfEnrolled, already-assigned course (the path self-enrol shape) is not gated.
    $employee = learnerEmployee();
    putOnLeave($employee);

    $result = app(AssignCourse::class)->attempt($employee, $this->course, 'Self', source: EnrollmentSource::SelfEnrolled);

    expect($result->isAssigned())->toBeTrue();
});
