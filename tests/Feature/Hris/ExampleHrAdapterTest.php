<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Hris\Adapters\ExampleHrAdapter;
use App\Hris\Data\EmployeeData;
use App\Hris\Data\TrainingCompletionData;
use App\Hris\Exceptions\HrisConnectionException;
use App\Hris\Exceptions\HrisUnsupportedOperation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The ExampleHR adapter is exercised entirely against Http::fake(). It is
 * deliberately never pointed at a live tenant here — see the IP note on the
 * adapter itself. These tests pin the MAPPING and the FAILURE BEHAVIOUR, which
 * is what we can honestly assert without a real credentialed response.
 */
function adapter(array $settings = []): ExampleHrAdapter
{
    return new ExampleHrAdapter($settings + [
        'base_url' => 'https://hr.example.test',
        'api_token' => 'test-token',
    ]);
}

function employeeRecord(array $overrides = []): array
{
    return array_merge([
        'employee_id' => 'SHR-001',
        'first_name' => 'Adaeze',
        'last_name' => 'Okafor',
        'email' => 'adaeze.okafor@example.test',
        'department' => 'Finance',
        'job_title' => 'Credit Officer',
        'location' => 'Lagos',
        'manager_id' => 'SHR-000',
        'status' => 'active',
    ], $overrides);
}

it('maps a documented employee payload into the neutral DTO', function () {
    Http::fake([
        'hr.example.test/*' => Http::response(['data' => [employeeRecord()]]),
    ]);

    $employees = iterator_to_array(adapter()->fetchEmployees());

    expect($employees)->toHaveCount(1);

    $employee = $employees[0];

    expect($employee)->toBeInstanceOf(EmployeeData::class)
        ->and($employee->externalId)->toBe('SHR-001')
        ->and($employee->firstName)->toBe('Adaeze')
        ->and($employee->lastName)->toBe('Okafor')
        ->and($employee->email)->toBe('adaeze.okafor@example.test')
        ->and($employee->department)->toBe('Finance')
        ->and($employee->jobTitle)->toBe('Credit Officer')
        ->and($employee->location)->toBe('Lagos')
        ->and($employee->managerExternalId)->toBe('SHR-000')
        ->and($employee->status)->toBe(EmployeeStatus::Active);
});

it('sends the tenant bearer token', function () {
    Http::fake(['hr.example.test/*' => Http::response(['data' => []])]);

    iterator_to_array(adapter()->fetchEmployees());

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token'));
});

it('accepts the alternate documented field names', function () {
    // The public docs are not explicit on every field, so the mapping accepts
    // the plausible alternates rather than silently producing empty values.
    Http::fake([
        'hr.example.test/*' => Http::response(['data' => [[
            'id' => 'SHR-009',
            'first_name' => 'Tunde',
            'last_name' => 'Balogun',
            'work_email' => 'tunde@example.test',
            'department_name' => 'Sales',
            'designation' => 'Account Manager',
            'line_manager_id' => 'SHR-002',
        ]]]),
    ]);

    $employee = iterator_to_array(adapter()->fetchEmployees())[0];

    expect($employee->externalId)->toBe('SHR-009')
        ->and($employee->email)->toBe('tunde@example.test')
        ->and($employee->department)->toBe('Sales')
        ->and($employee->jobTitle)->toBe('Account Manager')
        ->and($employee->managerExternalId)->toBe('SHR-002');
});

it('flattens a nested object into a plain label', function () {
    Http::fake([
        'hr.example.test/*' => Http::response(['data' => [employeeRecord([
            'department' => ['id' => 7, 'name' => 'Technology'],
        ])]]),
    ]);

    expect(iterator_to_array(adapter()->fetchEmployees())[0]->department)->toBe('Technology');
});

it('normalises leaver statuses and defaults everything else to active', function (string $vendorStatus, EmployeeStatus $expected) {
    // A false "exited" would wrongly withdraw somebody's training, so anything
    // not clearly indicating a leaver stays active. A dataset (rather than a
    // loop) so each case gets a fresh Http::fake — stubs otherwise accumulate.
    Http::fake([
        'hr.example.test/*' => Http::response(['data' => [employeeRecord(['status' => $vendorStatus])]]),
    ]);

    expect(iterator_to_array(adapter()->fetchEmployees())[0]->status)->toBe($expected);
})->with([
    'terminated' => ['terminated', EmployeeStatus::Exited],
    'resigned (upper-cased)' => ['RESIGNED', EmployeeStatus::Exited],
    'offboarded (padded)' => [' offboarded ', EmployeeStatus::Exited],
    'active' => ['active', EmployeeStatus::Active],
    'on leave is not a leaver' => ['on_leave', EmployeeStatus::Active],
    'unrecognised stays active' => ['something-unexpected', EmployeeStatus::Active],
]);

it('treats a missing status as active', function () {
    Http::fake([
        'hr.example.test/*' => Http::response(['data' => [employeeRecord(['status' => null])]]),
    ]);

    expect(iterator_to_array(adapter()->fetchEmployees())[0]->status)->toBe(EmployeeStatus::Active);
});

it('pages until a short page comes back', function () {
    config(['hris.example.page_size' => 2]);

    Http::fake([
        'hr.example.test/*' => Http::sequence()
            ->push(['data' => [employeeRecord(['employee_id' => 'A']), employeeRecord(['employee_id' => 'B'])]])
            ->push(['data' => [employeeRecord(['employee_id' => 'C'])]]),
    ]);

    $employees = iterator_to_array(adapter()->fetchEmployees());

    // Stopping on a short page works whether or not the API returns a total,
    // so an unexpected envelope can never spin forever.
    expect($employees)->toHaveCount(3)
        ->and(array_map(fn (EmployeeData $e) => $e->externalId, $employees))->toBe(['A', 'B', 'C']);

    Http::assertSentCount(2);
});

it('fetches a single employee and returns null when unknown', function () {
    Http::fake([
        'hr.example.test/v1/employees/SHR-001' => Http::response(['data' => employeeRecord()]),
        'hr.example.test/v1/employees/MISSING' => Http::response(['data' => null]),
    ]);

    expect(adapter()->fetchEmployee('SHR-001')?->externalId)->toBe('SHR-001')
        ->and(adapter()->fetchEmployee('MISSING'))->toBeNull();
});

it('wraps an HTTP failure in HrisConnectionException', function () {
    // No Guzzle or Laravel HTTP type may escape the port — callers depend on
    // our exceptions only.
    Http::fake(['hr.example.test/*' => Http::response(['message' => 'Unauthorised'], 401)]);

    expect(fn () => iterator_to_array(adapter()->fetchEmployees()))
        ->toThrow(HrisConnectionException::class);
});

it('wraps a transport failure in HrisConnectionException', function () {
    Http::fake(['hr.example.test/*' => fn () => throw new ConnectionException('dns failure')]);

    expect(fn () => iterator_to_array(adapter()->fetchEmployees()))
        ->toThrow(HrisConnectionException::class);
});

it('refuses training write-back, because ExampleHR publishes no such endpoint', function () {
    // The whole reason write-back is modelled as an optional capability. A silent
    // no-op here would let Sprint 6's outbox record a completion that reached
    // nothing at all.
    $adapter = adapter();

    expect($adapter->supportsWriteback())->toBeFalse()
        ->and($adapter->name())->toBe('example')
        ->and(fn () => $adapter->pushTrainingCompletion(new TrainingCompletionData(
            employeeExternalId: 'SHR-001',
            courseTitle: 'Anti-Money Laundering',
            completedAt: new DateTimeImmutable('2026-07-23 10:00:00'),
        )))->toThrow(HrisUnsupportedOperation::class);

    Http::assertNothingSent();
});
