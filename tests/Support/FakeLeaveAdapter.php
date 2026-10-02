<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Hris\Contracts\HrisEmployeeSource;
use App\Hris\Contracts\HrisLeaveSource;
use App\Hris\Contracts\HrisWriteback;
use App\Hris\Data\EmployeeData;
use App\Hris\Data\LeaveData;
use App\Hris\Data\TrainingCompletionData;
use App\Hris\Exceptions\HrisConnectionException;
use Carbon\CarbonImmutable;

/**
 * A fully test-controlled HR system for leave: the test sets the leave it returns,
 * whether the call fails, and can count how often the HRIS was actually called —
 * which is how the "assignment never touches the HRIS" guarantee is asserted.
 *
 * Registered in `hris.adapters` as `fake-leave` by {@see self::register()}.
 */
final class FakeLeaveAdapter implements HrisEmployeeSource, HrisLeaveSource, HrisWriteback
{
    /** @var list<LeaveData> */
    public static array $leave = [];

    public static bool $fails = false;

    public static bool $supportsLeave = true;

    /** How many times fetchLeave() was called. */
    public static int $calls = 0;

    /** @param array<string, mixed> $settings */
    public function __construct(public readonly array $settings = []) {}

    public static function register(): void
    {
        config()->set('hris.adapters.fake-leave', self::class);
        self::reset();
    }

    public static function reset(): void
    {
        self::$leave = [];
        self::$fails = false;
        self::$supportsLeave = true;
        self::$calls = 0;
    }

    public function name(): string
    {
        return 'fake-leave';
    }

    public function supportsLeave(): bool
    {
        return self::$supportsLeave;
    }

    public function fetchLeave(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        self::$calls++;

        if (self::$fails) {
            throw new HrisConnectionException('The HR system timed out.');
        }

        return self::$leave;
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

    public function pushTrainingCompletion(TrainingCompletionData $completion): void {}

    public function supportsWriteback(): bool
    {
        return false;
    }
}
