<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Hris\Adapters\ExampleHrAdapter;
use App\Hris\Adapters\MockHrisAdapter;
use App\Hris\Data\EmployeeData;
use App\Hris\Data\TrainingCompletionData;
use App\Hris\Exceptions\HrisUnsupportedOperation;
use App\Hris\HrisManager;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates the configured number of employees', function () {
    $employees = iterator_to_array((new MockHrisAdapter)->fetchEmployees());

    expect($employees)->toHaveCount((int) config('hris.mock.employee_count'))
        ->and($employees[0])->toBeInstanceOf(EmployeeData::class);
});

it('generates the same organisation on every run', function () {
    // Determinism is what makes the sync's idempotency test meaningful and keeps
    // demos stable — if this breaks, a "no changes" second sync is unprovable.
    $first = iterator_to_array((new MockHrisAdapter)->fetchEmployees());
    $second = iterator_to_array((new MockHrisAdapter)->fetchEmployees());

    expect(array_map(fn (EmployeeData $e) => $e->externalId, $first))
        ->toBe(array_map(fn (EmployeeData $e) => $e->externalId, $second))
        ->and($first[10]->firstName)->toBe($second[10]->firstName)
        ->and($first[10]->department)->toBe($second[10]->department);
});

it('builds a resolvable org tree with exactly one root', function () {
    $employees = iterator_to_array((new MockHrisAdapter)->fetchEmployees());

    $externalIds = array_map(fn (EmployeeData $e) => $e->externalId, $employees);
    $roots = array_filter($employees, fn (EmployeeData $e) => $e->managerExternalId === null);

    // Exactly one person at the top, and every other manager reference points at
    // somebody who actually exists — otherwise the sync's second pass would
    // silently leave dangling reporting lines.
    expect($roots)->toHaveCount(1)
        ->and($externalIds)->toBe(array_unique($externalIds));

    foreach ($employees as $employee) {
        if ($employee->managerExternalId !== null) {
            expect($externalIds)->toContain($employee->managerExternalId);
        }
    }
});

it('includes leavers so the exit sweep has something to act on', function () {
    $employees = iterator_to_array((new MockHrisAdapter)->fetchEmployees());

    $exited = array_filter($employees, fn (EmployeeData $e) => $e->status === EmployeeStatus::Exited);

    expect($exited)->not->toBeEmpty();
});

it('finds a single employee by external id and returns null for an unknown one', function () {
    $adapter = new MockHrisAdapter;

    expect($adapter->fetchEmployee('EMP-0001'))->toBeInstanceOf(EmployeeData::class)
        ->and($adapter->fetchEmployee('EMP-0001')->managerExternalId)->toBeNull()
        ->and($adapter->fetchEmployee('NOPE-9999'))->toBeNull();
});

it('resolves the adapter named on the tenant', function () {
    $mockTenant = Tenant::factory()->create(['hris_adapter' => 'mock']);
    $exampleTenant = Tenant::factory()->create(['hris_adapter' => 'example']);

    $manager = app(HrisManager::class);

    expect($manager->for($mockTenant))->toBeInstanceOf(MockHrisAdapter::class)
        ->and($manager->for($exampleTenant))->toBeInstanceOf(ExampleHrAdapter::class);
});

it('refuses an unknown adapter key instead of falling back', function () {
    // A typo must never silently sync against fabricated people in production.
    expect(fn () => app(HrisManager::class)->build('not-a-real-hris'))
        ->toThrow(InvalidArgumentException::class);
});

it('reports write-back capability honestly per adapter', function () {
    $completion = new TrainingCompletionData(
        employeeExternalId: 'EMP-0002',
        courseTitle: 'Anti-Money Laundering',
        completedAt: new DateTimeImmutable('2026-07-23 10:00:00'),
        score: 88,
    );

    // The mock accepts and discards, giving Sprint 6's outbox a happy path...
    $mock = new MockHrisAdapter;
    expect($mock->supportsWriteback())->toBeTrue()
        ->and(fn () => $mock->pushTrainingCompletion($completion))->not->toThrow(Throwable::class);

    // ...while ExampleHR publishes no such endpoint and says so loudly.
    $example = new ExampleHrAdapter;
    expect($example->supportsWriteback())->toBeFalse()
        ->and(fn () => $example->pushTrainingCompletion($completion))
        ->toThrow(HrisUnsupportedOperation::class);
});
