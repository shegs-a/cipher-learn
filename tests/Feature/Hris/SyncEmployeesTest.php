<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Hris\Contracts\HrisEmployeeSource;
use App\Hris\Contracts\HrisWriteback;
use App\Hris\Data\EmployeeData;
use App\Hris\Data\TrainingCompletionData;
use App\Hris\Sync\SyncEmployees;
use App\Models\Employee;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A stub HR system whose directory the test controls outright, so we can assert
 * on precise transitions (a person leaving, a job title changing) rather than
 * hoping the generated mock org happens to contain them.
 *
 * Registered as a real adapter in the `hris.adapters` registry rather than
 * mocked in, so these tests exercise the genuine HrisManager resolution path
 * instead of bypassing it.
 */
class TestHrisAdapter implements HrisEmployeeSource, HrisWriteback
{
    /** @var list<EmployeeData> The directory the current test wants returned. */
    public static array $directory = [];

    /** @param array<string, mixed> $settings */
    public function __construct(private readonly array $settings = []) {}

    public function fetchEmployees(): iterable
    {
        return static::$directory;
    }

    public function fetchEmployee(string $externalId): ?EmployeeData
    {
        foreach (static::$directory as $employee) {
            if ($employee->externalId === $externalId) {
                return $employee;
            }
        }

        return null;
    }

    public function name(): string
    {
        return 'test';
    }

    public function pushTrainingCompletion(TrainingCompletionData $completion): void {}

    public function supportsWriteback(): bool
    {
        return true;
    }
}

function person(string $id, string $title = 'Analyst', ?string $managerId = null, EmployeeStatus $status = EmployeeStatus::Active): EmployeeData
{
    return new EmployeeData(
        externalId: $id,
        firstName: 'First'.$id,
        lastName: 'Last'.$id,
        email: strtolower($id === '' ? 'blank' : $id).'@example.test',
        department: 'Operations',
        jobTitle: $title,
        location: 'Lagos',
        managerExternalId: $managerId,
        status: $status,
    );
}

/**
 * Run a real sync against a controlled directory.
 *
 * @param  list<EmployeeData>  $employees
 */
function syncWith(Tenant $tenant, array $employees, bool $force = false): SyncRun
{
    TestHrisAdapter::$directory = $employees;

    return app(SyncEmployees::class)->forTenant($tenant, $force);
}

/**
 * A directory of $count active people, EMP-1..EMP-N. First is the manager.
 *
 * @return list<EmployeeData>
 */
function directoryOf(int $count): array
{
    $people = [];
    for ($i = 1; $i <= $count; $i++) {
        $people[] = person("EMP-{$i}", 'Analyst', $i === 1 ? null : 'EMP-1');
    }

    return $people;
}

beforeEach(function () {
    config(['hris.adapters.test' => TestHrisAdapter::class]);
    TestHrisAdapter::$directory = [];

    $this->tenant = Tenant::factory()->create(['hris_adapter' => 'test']);
});

it('creates employees from the HR directory and records the run', function () {
    $run = syncWith($this->tenant, [
        person('EMP-1', 'Head of Operations'),
        person('EMP-2', 'Analyst', 'EMP-1'),
    ]);

    expect($run->type)->toBe('employee_sync')
        ->and($run->status)->toBe('completed')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->stats['created'])->toBe(2)
        ->and($run->stats['errors'])->toBe(0)
        ->and($run->stats['adapter'])->toBe('test');

    app(Tenancy::class)->runFor($this->tenant, function () {
        expect(Employee::count())->toBe(2)
            ->and(Employee::where('external_id', 'EMP-2')->first()->job_title)->toBe('Analyst');
    });
});

it('is idempotent — a second run against unchanged data writes nothing', function () {
    // This is the property that makes the sync safe to schedule. It depends on
    // isDirty() checking, not blind saves.
    $directory = [person('EMP-1'), person('EMP-2', 'Analyst', 'EMP-1')];

    syncWith($this->tenant, $directory);
    $second = syncWith($this->tenant, $directory);

    expect($second->stats['created'])->toBe(0)
        ->and($second->stats['updated'])->toBe(0)
        ->and($second->stats['unchanged'])->toBe(2)
        ->and($second->stats['exited'])->toBe(0);
});

it('resolves reporting lines even when a manager is listed after their report', function () {
    // Report first, manager second — the two-pass link is what makes this work.
    syncWith($this->tenant, [
        person('EMP-2', 'Analyst', 'EMP-1'),
        person('EMP-1', 'Head of Operations'),
    ]);

    app(Tenancy::class)->runFor($this->tenant, function () {
        $report = Employee::where('external_id', 'EMP-2')->first();
        $manager = Employee::where('external_id', 'EMP-1')->first();

        expect($report->manager_id)->toBe($manager->id)
            ->and($manager->manager_id)->toBeNull();
    });
});

it('updates a changed field and reports it as updated', function () {
    syncWith($this->tenant, [person('EMP-1', 'Analyst')]);
    $run = syncWith($this->tenant, [person('EMP-1', 'Senior Analyst')]);

    expect($run->stats['updated'])->toBe(1)
        ->and($run->stats['unchanged'])->toBe(0);

    app(Tenancy::class)->runFor($this->tenant, function () {
        expect(Employee::where('external_id', 'EMP-1')->first()->job_title)->toBe('Senior Analyst');
    });
});

it('marks a person the HR system stopped listing as exited, without deleting them', function () {
    syncWith($this->tenant, [person('EMP-1'), person('EMP-2')]);

    // EMP-2 has left the company and no longer appears in the directory.
    $run = syncWith($this->tenant, [person('EMP-1')]);

    expect($run->stats['exited'])->toBe(1);

    app(Tenancy::class)->runFor($this->tenant, function () {
        // Still present — learning history and certificates must survive.
        expect(Employee::count())->toBe(2)
            ->and(Employee::where('external_id', 'EMP-2')->first()->status)
            ->toBe(EmployeeStatus::Exited);
    });
});

it('leaves locally-created employees alone during the exit sweep', function () {
    // An employee with no external_id was not created by the HRIS, so the sync
    // has no authority to deactivate them.
    app(Tenancy::class)->runFor($this->tenant, function () {
        Employee::factory()->create(['external_id' => null, 'status' => EmployeeStatus::Active]);
    });

    syncWith($this->tenant, [person('EMP-1')]);

    app(Tenancy::class)->runFor($this->tenant, function () {
        expect(Employee::whereNull('external_id')->first()->status)->toBe(EmployeeStatus::Active);
    });
});

it('never touches another tenant\'s employees', function () {
    $other = Tenant::factory()->create(['hris_adapter' => 'test']);

    syncWith($this->tenant, [person('EMP-1'), person('EMP-2')]);
    syncWith($other, [person('EMP-1')]);

    // Same external ids in both tenants, scoped independently: the second
    // tenant's sync must not exit or overwrite the first tenant's people.
    app(Tenancy::class)->runFor($this->tenant, function () {
        expect(Employee::count())->toBe(2)
            ->and(Employee::where('external_id', 'EMP-2')->first()->status)
            ->toBe(EmployeeStatus::Active);
    });

    app(Tenancy::class)->runFor($other, function () {
        expect(Employee::count())->toBe(1);
    });
});

it('counts a record with no external id as an error instead of duplicating it', function () {
    $run = syncWith($this->tenant, [person('EMP-1'), person('')]);

    expect($run->stats['errors'])->toBe(1)
        ->and($run->stats['created'])->toBe(1);
});

it('withholds the sweep and flags the run when an implausible fraction would exit', function () {
    // The scenario the guard exists for: a healthy 12-person directory, then a
    // truncated/empty response listing only one person. Exiting 11 of 12 is what
    // a broken HR feed looks like — refuse it.
    syncWith($this->tenant, directoryOf(12));

    $run = syncWith($this->tenant, [person('EMP-1')]);

    expect($run->status)->toBe('failed')
        ->and($run->stats['exit_guard_tripped'])->toBeTrue()
        ->and($run->stats['exited'])->toBe(0)
        ->and($run->stats['would_exit'])->toBe(11)
        ->and($run->stats['active_total'])->toBe(12);

    // Nobody was actually exited — the whole workforce is intact.
    app(Tenancy::class)->runFor($this->tenant, function () {
        expect(Employee::where('status', EmployeeStatus::Active)->count())->toBe(12);
    });
});

it('applies the withheld sweep when forced', function () {
    syncWith($this->tenant, directoryOf(12));

    // --force: a genuine, confirmed reduction in force.
    $run = syncWith($this->tenant, [person('EMP-1')], force: true);

    expect($run->status)->toBe('completed')
        ->and($run->stats['exit_guard_tripped'])->toBeFalse()
        ->and($run->stats['exited'])->toBe(11);

    app(Tenancy::class)->runFor($this->tenant, function () {
        expect(Employee::where('status', EmployeeStatus::Active)->count())->toBe(1)
            ->and(Employee::where('status', EmployeeStatus::Exited)->count())->toBe(11);
    });
});

it('does not trip on an ordinary handful of leavers in a large workforce', function () {
    syncWith($this->tenant, directoryOf(12));

    // One person leaves out of twelve — well under the 20% threshold.
    $run = syncWith($this->tenant, directoryOf(11));

    expect($run->status)->toBe('completed')
        ->and($run->stats['exit_guard_tripped'])->toBeFalse()
        ->and($run->stats['exited'])->toBe(1)
        ->and($run->stats['would_exit'])->toBe(1);
});

it('does not trip on the first sync into an empty tenant', function () {
    // Everyone is newly created, so nobody would be exited — fraction is zero.
    $run = syncWith($this->tenant, directoryOf(12));

    expect($run->status)->toBe('completed')
        ->and($run->stats['exit_guard_tripped'])->toBeFalse()
        ->and($run->stats['would_exit'])->toBe(0);
});

it('does not trip on a tiny workforce below the minimum, even at a high fraction', function () {
    // Three people, then one: 2 of 3 (67%) is over the threshold, but the ratio
    // is meaningless on a workforce this small, so the sweep proceeds normally.
    syncWith($this->tenant, directoryOf(3));

    $run = syncWith($this->tenant, [person('EMP-1')]);

    expect($run->status)->toBe('completed')
        ->and($run->stats['exit_guard_tripped'])->toBeFalse()
        ->and($run->stats['exited'])->toBe(2);
});

it('syncs the real mock adapter end to end', function () {
    $tenant = Tenant::factory()->create(['hris_adapter' => 'mock']);

    $run = app(SyncEmployees::class)->forTenant($tenant);

    $expected = (int) config('hris.mock.employee_count');

    expect($run->status)->toBe('completed')
        ->and($run->stats['created'])->toBe($expected)
        ->and($run->stats['adapter'])->toBe('mock');

    app(Tenancy::class)->runFor($tenant, function () use ($expected) {
        expect(Employee::count())->toBe($expected)
            // The generated org has leavers, and reporting lines that resolved.
            ->and(Employee::where('status', EmployeeStatus::Exited)->count())->toBeGreaterThan(0)
            ->and(Employee::whereNotNull('manager_id')->count())->toBeGreaterThan(0);
    });
});
