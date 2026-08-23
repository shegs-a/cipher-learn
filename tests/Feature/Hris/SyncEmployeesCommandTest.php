<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Hris\Contracts\HrisEmployeeSource;
use App\Hris\Contracts\HrisWriteback;
use App\Hris\Data\EmployeeData;
use App\Hris\Data\TrainingCompletionData;
use App\Models\Employee;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Adapter whose directory the test sets, registered in the real registry so the
 * command's own resolution path runs. Shares the shape of the sync test's stub.
 */
class GuardTestAdapter implements HrisEmployeeSource, HrisWriteback
{
    /** @var list<EmployeeData> */
    public static array $directory = [];

    /** @param array<string, mixed> $settings */
    public function __construct(private readonly array $settings = []) {}

    public function fetchEmployees(): iterable
    {
        return static::$directory;
    }

    public function fetchEmployee(string $externalId): ?EmployeeData
    {
        return null;
    }

    public function name(): string
    {
        return 'guard-test';
    }

    public function pushTrainingCompletion(TrainingCompletionData $completion): void {}

    public function supportsWriteback(): bool
    {
        return true;
    }
}

/** @return list<EmployeeData> */
function guardDirectory(int $count): array
{
    $people = [];
    for ($i = 1; $i <= $count; $i++) {
        $people[] = new EmployeeData(
            externalId: "EMP-{$i}",
            firstName: "First{$i}",
            lastName: "Last{$i}",
        );
    }

    return $people;
}

it('syncs a single tenant by slug', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'hris_adapter' => 'mock']);

    $this->artisan('hris:sync-employees', ['--tenant' => 'acme'])
        ->assertSuccessful();

    app(Tenancy::class)->runFor($tenant, function () {
        expect(Employee::count())->toBe((int) config('hris.mock.employee_count'));
    });
});

it('syncs every tenant with --all', function () {
    Tenant::factory()->create(['slug' => 'one', 'hris_adapter' => 'mock']);
    Tenant::factory()->create(['slug' => 'two', 'hris_adapter' => 'mock']);

    $this->artisan('hris:sync-employees', ['--all' => true])->assertSuccessful();

    expect(Employee::withoutGlobalScopes()->count())
        ->toBe(2 * (int) config('hris.mock.employee_count'));
});

it('fails when a named tenant does not exist', function () {
    // A typo must not report success having synced nobody — otherwise a
    // scheduled sync can quietly do nothing indefinitely.
    $this->artisan('hris:sync-employees', ['--tenant' => 'does-not-exist'])
        ->assertFailed();
});

it('succeeds quietly when --all finds no tenants at all', function () {
    // Distinct from the case above: a fresh install genuinely has nothing to do.
    $this->artisan('hris:sync-employees', ['--all' => true])->assertSuccessful();
});

it('rejects being given both --tenant and --all', function () {
    $this->artisan('hris:sync-employees', ['--tenant' => 'acme', '--all' => true])
        ->assertFailed();
});

it('requires one of --tenant or --all', function () {
    $this->artisan('hris:sync-employees')->assertFailed();
});

it('exits non-zero and withholds the sweep when the mass-exit guard trips', function () {
    config(['hris.adapters.guard-test' => GuardTestAdapter::class]);
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'hris_adapter' => 'guard-test']);

    GuardTestAdapter::$directory = guardDirectory(12);
    $this->artisan('hris:sync-employees', ['--tenant' => 'acme'])->assertSuccessful();

    // The directory collapses to one person — a broken feed, not 11 resignations.
    GuardTestAdapter::$directory = guardDirectory(1);
    $this->artisan('hris:sync-employees', ['--tenant' => 'acme'])->assertFailed();

    app(Tenancy::class)->runFor($tenant, function () {
        expect(Employee::where('status', EmployeeStatus::Active)->count())->toBe(12);
    });
});

it('applies the sweep when --force is given', function () {
    config(['hris.adapters.guard-test' => GuardTestAdapter::class]);
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'hris_adapter' => 'guard-test']);

    GuardTestAdapter::$directory = guardDirectory(12);
    $this->artisan('hris:sync-employees', ['--tenant' => 'acme'])->assertSuccessful();

    GuardTestAdapter::$directory = guardDirectory(1);
    $this->artisan('hris:sync-employees', ['--tenant' => 'acme', '--force' => true])
        ->assertSuccessful();

    app(Tenancy::class)->runFor($tenant, function () {
        expect(Employee::where('status', EmployeeStatus::Exited)->count())->toBe(11);
    });
});
