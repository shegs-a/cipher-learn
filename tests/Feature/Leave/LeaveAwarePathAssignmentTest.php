<?php

declare(strict_types=1);

use App\Actions\Learning\AssignLearningPath;
use App\Actions\Learning\AssignLearningPathOrgWide;
use App\Actions\Learning\AssignLearningPathToEmployees;
use App\Assignment\AssignmentBlockedException;
use App\Enums\AssignmentOutcome;
use App\Enums\AssignmentSkipReason;
use App\Enums\EmployeeStatus;
use App\Enums\EnrollmentSource;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\Enrollment;
use App\Models\LearningPath;
use App\Models\PathEnrollment;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AssignmentBlockedNotification;
use App\Notifications\BulkAssignmentBlockedNotification;
use App\Notifications\CourseAssignedNotification;
use App\Notifications\PathAssignedNotification;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-02 12:00:00');

    $this->tenant = Tenant::factory()->create(['timezone' => 'UTC']);
    app(Tenancy::class)->set($this->tenant->id);

    $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id]);

    $this->path = LearningPath::factory()->create(['name' => 'Leadership Track']);
    $this->courses = collect(range(1, 3))->map(fn () => Course::factory()->create());
    $this->path->courses()->attach($this->courses->mapWithKeys(fn ($c, $i) => [$c->id => ['position' => $i + 1]])->all());
    $this->path = $this->path->fresh();

    Notification::fake();
});

function pathLearner(array $attrs = []): Employee
{
    $user = User::factory()->create(['tenant_id' => app(Tenancy::class)->id()]);

    return Employee::factory()->create($attrs + ['user_id' => $user->id]);
}

function pathLeave(Employee $employee, string $from = '2026-09-28', string $to = '2026-10-07'): void
{
    EmployeeLeave::factory()->for($employee)->create(['starts_on' => $from, 'ends_on' => $to]);
}

function assignPath(Employee $employee, LearningPath $path, ?User $by = null)
{
    return app(AssignLearningPath::class)->attempt($path, $employee, 'Leadership pipeline', assignedBy: $by);
}

describe('individual learning-path assignment', function () {
    it('blocks an employee on leave when the policy is disabled, creating nothing at all', function () {
        $employee = pathLearner();
        pathLeave($employee);

        $result = assignPath($employee, $this->path, $this->admin);

        expect($result->outcome)->toBe(AssignmentOutcome::Blocked)
            ->and(PathEnrollment::count())->toBe(0)    // no path membership
            ->and(Enrollment::count())->toBe(0);       // and no course enrolments from the path
    });

    it('sends the employee no path or course notification, and tells the admin', function () {
        $employee = pathLearner(['first_name' => 'Jane', 'last_name' => 'Doe']);
        pathLeave($employee);

        assignPath($employee, $this->path, $this->admin);

        Notification::assertNothingSentTo($employee->user);
        Notification::assertNotSentTo($employee->user, PathAssignedNotification::class);
        Notification::assertNotSentTo($employee->user, CourseAssignedNotification::class);
        Notification::assertSentTo($this->admin, AssignmentBlockedNotification::class, fn ($n) => $n->assignmentType === 'learning path'
            && $n->subjectName === 'Leadership Track'
            && $n->employeeName === 'Jane Doe');
    });

    it('audits the blocked attempt against the path', function () {
        $employee = pathLearner();
        pathLeave($employee);

        assignPath($employee, $this->path, $this->admin);

        $entry = AuditLog::query()->where('event', 'assignment.blocked')->sole();

        expect($entry->new_values)->toMatchArray([
            'subject_type' => 'learning path',
            'subject_id' => $this->path->id,
            'block_reason' => 'employee_on_leave',
            'leave_start' => '2026-09-28',
            'leave_end' => '2026-10-07',
        ]);
    });

    it('assigns the whole path when the policy allows it, auditing the leave ONCE, not once per course', function () {
        $this->tenant->update(['settings' => [Tenant::SETTING_ALLOW_ASSIGNMENT_ON_LEAVE => true]]);
        $employee = pathLearner();
        pathLeave($employee);

        $result = assignPath($employee, $this->path, $this->admin);

        expect($result->isAssigned())->toBeTrue()
            ->and($result->assignedWhileOnLeave)->toBeTrue()
            ->and(PathEnrollment::count())->toBe(1)
            ->and(Enrollment::count())->toBe(3)
            ->and(Enrollment::first()->evidence['assigned_during_leave'])->toEqual(['start' => '2026-09-28', 'end' => '2026-10-07'])
            ->and(AuditLog::query()->where('event', 'assignment.allowed_on_leave')->count())->toBe(1);

        Notification::assertSentToTimes($employee->user, PathAssignedNotification::class, 1);
    });

    it('keeps today\'s behaviour for an employee who is not on leave', function () {
        $employee = pathLearner();

        $result = assignPath($employee, $this->path, $this->admin);

        expect($result->isAssigned())->toBeTrue()
            ->and($result->assignedWhileOnLeave)->toBeFalse()
            ->and(PathEnrollment::count())->toBe(1)
            ->and(Enrollment::count())->toBe(3)
            ->and(AuditLog::query()->whereIn('event', ['assignment.blocked', 'assignment.allowed_on_leave'])->count())->toBe(0);

        Notification::assertSentToTimes($employee->user, PathAssignedNotification::class, 1);
        Notification::assertNotSentTo($employee->user, CourseAssignedNotification::class);
        Notification::assertNotSentTo($this->admin, AssignmentBlockedNotification::class);
    });

    it('throws from the strict handle() instead of returning a membership that does not exist', function () {
        $employee = pathLearner();
        pathLeave($employee);

        expect(fn () => app(AssignLearningPath::class)->handle($this->path, $employee, 'Pipeline', assignedBy: $this->admin))
            ->toThrow(AssignmentBlockedException::class);

        expect(PathEnrollment::count())->toBe(0);
    });

    it('does not gate a learner enrolling THEMSELVES in a path', function () {
        $employee = pathLearner();
        pathLeave($employee);

        $result = app(AssignLearningPath::class)->attempt($this->path, $employee, 'Self-enrolled', source: EnrollmentSource::SelfEnrolled);

        expect($result->isAssigned())->toBeTrue()
            ->and(Enrollment::count())->toBe(3);
    });

    it('does not refuse (or report as blocked) a path the employee already holds in full', function () {
        $employee = pathLearner();
        assignPath($employee, $this->path, $this->admin);
        pathLeave($employee); // goes on leave afterwards

        $result = assignPath($employee, $this->path, $this->admin);

        expect($result->outcome)->toBe(AssignmentOutcome::Skipped)
            ->and($result->skipReason)->toBe(AssignmentSkipReason::AlreadyAssigned);

        Notification::assertNotSentTo($this->admin, AssignmentBlockedNotification::class);
    });

    it('blocks a re-run that would add a NEW course to someone now on leave, adding nothing', function () {
        $employee = pathLearner();
        assignPath($employee, $this->path, $this->admin);

        // The path later gains a fourth course, and the employee is on leave.
        $extra = Course::factory()->create();
        $this->path->courses()->attach($extra->id, ['position' => 4]);
        pathLeave($employee);

        $result = assignPath($employee, $this->path->fresh(), $this->admin);

        expect($result->isBlocked())->toBeTrue()
            ->and(Enrollment::count())->toBe(3)                                        // still the original three
            ->and(Enrollment::where('course_id', $extra->id)->exists())->toBeFalse();
    });
});

describe('bulk learning-path assignment', function () {
    it('processes everyone: assigns the eligible, blocks those on leave, skips the rest, and never partially assigns', function () {
        $free = collect(range(1, 3))->map(fn () => pathLearner());
        $away = collect(range(1, 2))->map(fn () => pathLearner());
        $away->each(fn ($e) => pathLeave($e));
        $already = pathLearner();
        assignPath($already, $this->path, $this->admin);
        $exited = pathLearner(['status' => EmployeeStatus::Exited]);

        $result = app(AssignLearningPathToEmployees::class)->handle(
            $this->path,
            $free->concat($away)->push($already)->push($exited),
            'Leadership pipeline',
            assignedBy: $this->admin,
        );

        expect($result->total())->toBe(7)
            ->and($result->assigned())->toBe(3)
            ->and($result->blocked())->toBe(2)
            ->and($result->skippedAlreadyAssigned())->toBe(1)
            ->and($result->skippedOther())->toBe(1);

        // The blocked got nothing of the path; the eligible got all of it.
        foreach ($away as $employee) {
            expect(PathEnrollment::where('employee_id', $employee->id)->exists())->toBeFalse()
                ->and(Enrollment::where('employee_id', $employee->id)->exists())->toBeFalse();
        }
        foreach ($free as $employee) {
            expect(Enrollment::where('employee_id', $employee->id)->count())->toBe(3);
        }
    });

    it('sends one summary to the admin, not one per blocked employee', function () {
        collect(range(1, 4))->each(fn () => pathLeave(pathLearner()));
        pathLearner();

        app(AssignLearningPathOrgWide::class)->handle($this->path, 'Pipeline', assignedBy: $this->admin);

        Notification::assertSentToTimes($this->admin, BulkAssignmentBlockedNotification::class, 1);
        Notification::assertNotSentTo($this->admin, AssignmentBlockedNotification::class);
        Notification::assertSentTo($this->admin, BulkAssignmentBlockedNotification::class, fn ($n) => $n->assignmentType === 'learning path'
            && $n->blocked === 4 && $n->total === 5);
    });

    it('applies the policy to an organisation-wide path assignment', function () {
        pathLeave(pathLearner());
        pathLearner();
        pathLearner();

        $result = app(AssignLearningPathOrgWide::class)->handle($this->path, 'Pipeline', assignedBy: $this->admin);

        expect($result->assigned())->toBe(2)
            ->and($result->blocked())->toBe(1)
            ->and(PathEnrollment::count())->toBe(2);
    });

    it('applies the policy to a department path assignment, and only that department', function () {
        pathLeave(pathLearner(['department' => 'Finance']));
        pathLearner(['department' => 'Finance']);
        $sales = pathLearner(['department' => 'Sales']);

        $result = app(AssignLearningPathOrgWide::class)->handle($this->path, 'Pipeline', department: 'Finance', assignedBy: $this->admin);

        expect($result->total())->toBe(2)
            ->and($result->assigned())->toBe(1)
            ->and($result->blocked())->toBe(1)
            ->and(PathEnrollment::where('employee_id', $sales->id)->exists())->toBeFalse();

        $summary = AuditLog::query()->where('event', 'assignment.bulk_completed')->sole();
        expect($summary->new_values['scope'])->toBe('department:Finance');
    });

    it('assigns everyone when the policy allows it', function () {
        $this->tenant->update(['settings' => [Tenant::SETTING_ALLOW_ASSIGNMENT_ON_LEAVE => true]]);
        pathLeave(pathLearner());
        pathLearner();

        $result = app(AssignLearningPathOrgWide::class)->handle($this->path, 'Pipeline', assignedBy: $this->admin);

        expect($result->assigned())->toBe(2)
            ->and($result->assignedWhileOnLeave())->toBe(1)
            ->and($result->blocked())->toBe(0);
    });
});
