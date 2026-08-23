<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Hris\Contracts\HrisEmployeeSource;
use App\Hris\Contracts\HrisWriteback;
use App\Hris\Data\EmployeeData;
use App\Hris\Data\TrainingCompletionData;
use App\Hris\Exceptions\HrisConnectionException;

/**
 * A test adapter that claims write-back support but always fails the push with a
 * transient {@see HrisConnectionException} — to exercise ProcessOutbox's retry /
 * back-off / eventual-`failed` path. Registered into `hris.adapters` by the test.
 */
final class FailingWritebackAdapter implements HrisEmployeeSource, HrisWriteback
{
    /** @param array<string, mixed> $settings */
    public function __construct(public readonly array $settings = []) {}

    public function name(): string
    {
        return 'failing';
    }

    /** @return iterable<int, EmployeeData> */
    public function fetchEmployees(): iterable
    {
        return [];
    }

    public function fetchEmployee(string $externalId): ?EmployeeData
    {
        return null;
    }

    public function pushTrainingCompletion(TrainingCompletionData $completion): void
    {
        throw HrisConnectionException::for('failing', 'simulated HR API outage');
    }

    public function supportsWriteback(): bool
    {
        return true;
    }
}
