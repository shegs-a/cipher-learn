<?php

declare(strict_types=1);

namespace App\Hris\Adapters;

use App\Enums\EmployeeStatus;
use App\Hris\Contracts\HrisEmployeeSource;
use App\Hris\Contracts\HrisLeaveSource;
use App\Hris\Contracts\HrisWriteback;
use App\Hris\Data\EmployeeData;
use App\Hris\Data\LeaveData;
use App\Hris\Data\TrainingCompletionData;
use Carbon\CarbonImmutable;

/**
 * An in-memory HR system: a realistic, fully deterministic organisation.
 *
 * This is the default adapter and the workhorse for Sprints 2–8. CipherLearn
 * is an independent LMS, so it must be completely demonstrable and testable with
 * no HR system connected — this is what makes that true.
 *
 * DETERMINISM IS THE POINT. The same seed produces the same people, the same
 * reporting lines and the same leavers on every run, so:
 *   - the sync's idempotency test means something (a second run really is a no-op),
 *   - demos don't reshuffle between runs,
 *   - Sprint 4's assignment rules can be reasoned about against a known org.
 * It uses a seeded linear congruential generator rather than faker precisely
 * because it must not drift when a dependency changes its generation internals.
 *
 * The generated org is a three-level tree: one MD, a handful of department heads,
 * and their reports — enough shape for the Sprint 6 manager view to be real.
 */
final class MockHrisAdapter implements HrisEmployeeSource, HrisLeaveSource, HrisWriteback
{
    /** Deterministic PRNG state; re-seeded at the start of every generation. */
    private int $randomState = 0;

    /** @var list<EmployeeData>|null Memoised so repeated calls in one run are stable and cheap. */
    private ?array $employees = null;

    /** @param array<string, mixed> $settings Per-tenant overrides from tenants.settings. */
    public function __construct(private readonly array $settings = []) {}

    public function name(): string
    {
        return 'mock';
    }

    /**
     * @return iterable<int, EmployeeData>
     */
    public function fetchEmployees(): iterable
    {
        return $this->generate();
    }

    public function fetchEmployee(string $externalId): ?EmployeeData
    {
        foreach ($this->generate() as $employee) {
            if ($employee->externalId === $externalId) {
                return $employee;
            }
        }

        return null;
    }

    /**
     * The mock accepts write-back and discards it.
     *
     * Deliberately a successful no-op rather than a throw: it gives Sprint 6's
     * outbox a working happy path to develop and test against, and it is the
     * honest behaviour for a fake HR system — it genuinely "recorded" the
     * completion as faithfully as an in-memory system can. Contrast
     * ExampleHrAdapter, which throws because the capability truly is absent.
     */
    public function pushTrainingCompletion(TrainingCompletionData $completion): void
    {
        // Intentionally empty — see the docblock.
    }

    public function supportsWriteback(): bool
    {
        return true;
    }

    public function supportsLeave(): bool
    {
        return true;
    }

    /**
     * Deterministic leave for the generated org, anchored on today's date.
     *
     * Each active employee is bucketed by a hash of (seed, external id) — NOT by the
     * employee generator's PRNG, so adding leave never reshuffles the org. Roughly
     * 17% of people are on leave right now, 9% have leave coming up and 7% had some
     * recently, which gives the demo and the tests every case the policy cares about:
     * current, upcoming and past. Only leave overlapping the requested window is
     * returned, as a real adapter would.
     *
     * @return iterable<int, LeaveData>
     */
    public function fetchLeave(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $today = CarbonImmutable::today();
        $seed = (int) ($this->settings['seed'] ?? config('hris.mock.seed', 20260722));
        $types = ['Annual', 'Sick', 'Parental', 'Study'];

        $leave = [];

        foreach ($this->generate() as $employee) {
            if ($employee->status === EmployeeStatus::Exited) {
                continue;
            }

            $hash = crc32($seed.'|'.$employee->externalId);
            $bucket = $hash % 100;
            $length = 4 + intdiv($hash, 100) % 11; // 4–14 days
            $type = $types[intdiv($hash, 1000) % count($types)];

            $startsOn = match (true) {
                $bucket < 17 => $today->subDays(intdiv($hash, 10000) % 3),
                $bucket < 26 => $today->addDays(5 + intdiv($hash, 10000) % 25),
                $bucket < 33 => $today->subDays(12 + intdiv($hash, 10000) % 30),
                default => null,
            };

            if ($startsOn === null) {
                continue;
            }

            $endsOn = $startsOn->addDays($length - 1);

            // A real API only returns leave overlapping the requested window.
            if ($endsOn->lt($from) || $startsOn->gt($to)) {
                continue;
            }

            $leave[] = new LeaveData(
                employeeExternalId: $employee->externalId,
                leaveExternalId: 'LV-'.$employee->externalId.'-'.$startsOn->format('Ymd'),
                startsOn: $startsOn,
                endsOn: $endsOn,
                type: $type,
                // The mock behaves like a vendor with an authoritative flag.
                isCurrent: $startsOn->lte($today) && $endsOn->gte($today),
            );
        }

        return $leave;
    }

    /**
     * Build the organisation. Memoised per adapter instance.
     *
     * @return list<EmployeeData>
     */
    private function generate(): array
    {
        if ($this->employees !== null) {
            return $this->employees;
        }

        $count = (int) ($this->settings['employee_count'] ?? config('hris.mock.employee_count', 45));
        $this->randomState = (int) ($this->settings['seed'] ?? config('hris.mock.seed', 20260722));

        $employees = [];

        // Level 1 — the managing director, top of the tree, reports to nobody.
        $employees[] = $this->makeEmployee(1, 'Executive', 'Managing Director', null);

        // Level 2 — one head per department. Capped at the department list so a
        // small employee_count still yields a sane tree rather than all chiefs.
        $departments = $this->departments();
        $headCount = min(count($departments), max(1, intdiv($count, 8)));
        $headExternalIds = [];

        for ($i = 0; $i < $headCount; $i++) {
            $sequence = $i + 2;
            $department = $departments[$i];
            $employees[] = $this->makeEmployee($sequence, $department, 'Head of '.$department, 'EMP-0001');
            $headExternalIds[$department] = $this->externalId($sequence);
        }

        // Level 3 — individual contributors, spread across the departments that
        // actually have a head so every report has a real reporting line.
        $headedDepartments = array_keys($headExternalIds);

        for ($sequence = $headCount + 2; $sequence <= $count; $sequence++) {
            $department = $headedDepartments[$this->nextInt(count($headedDepartments))];

            // A small, fixed proportion of leavers so the sync's exit sweep and
            // the "preserve history, never delete" rule have something to bite on.
            $status = $this->nextInt(100) < 7 ? EmployeeStatus::Exited : EmployeeStatus::Active;

            $employees[] = $this->makeEmployee(
                $sequence,
                $department,
                $this->jobTitle($department),
                $headExternalIds[$department],
                $status,
            );
        }

        return $this->employees = $employees;
    }

    private function makeEmployee(
        int $sequence,
        string $department,
        string $jobTitle,
        ?string $managerExternalId,
        EmployeeStatus $status = EmployeeStatus::Active,
    ): EmployeeData {
        $firstName = $this->firstNames()[$this->nextInt(count($this->firstNames()))];
        $lastName = $this->lastNames()[$this->nextInt(count($this->lastNames()))];

        return new EmployeeData(
            externalId: $this->externalId($sequence),
            firstName: $firstName,
            lastName: $lastName,
            // Sequence-suffixed so two people sharing a name still get unique
            // addresses — email is a join candidate for Sprint 3's login linking.
            email: sprintf('%s.%s%d@example.test', strtolower($firstName), strtolower($lastName), $sequence),
            department: $department,
            jobTitle: $jobTitle,
            location: $this->locations()[$this->nextInt(count($this->locations()))],
            managerExternalId: $managerExternalId,
            status: $status,
        );
    }

    private function externalId(int $sequence): string
    {
        return sprintf('EMP-%04d', $sequence);
    }

    /**
     * Deterministic pseudo-random integer in [0, $bound).
     *
     * A plain linear congruential generator (the glibc constants). Not remotely
     * cryptographic — it must only be repeatable, which `mt_rand()` seeded
     * globally would not be once anything else in the process draws from it.
     */
    private function nextInt(int $bound): int
    {
        $this->randomState = ($this->randomState * 1103515245 + 12345) & 0x7FFFFFFF;

        return $bound > 0 ? $this->randomState % $bound : 0;
    }

    /** @return list<string> */
    private function departments(): array
    {
        return ['Operations', 'Finance', 'Sales', 'Technology', 'People', 'Customer Support'];
    }

    /** @return list<string> */
    private function locations(): array
    {
        return ['Lagos', 'Abuja', 'Port Harcourt', 'Nairobi', 'Accra', 'Remote'];
    }

    /** @return list<string> */
    private function firstNames(): array
    {
        return [
            'Adaeze', 'Chidi', 'Ngozi', 'Emeka', 'Folake', 'Tunde', 'Amina', 'Yusuf',
            'Zainab', 'Segun', 'Ifeoma', 'Kelechi', 'Bisi', 'Musa', 'Halima', 'Obinna',
            'Temitope', 'Uche', 'Fatima', 'Ibrahim',
        ];
    }

    /** @return list<string> */
    private function lastNames(): array
    {
        return [
            'Okafor', 'Adeyemi', 'Balogun', 'Eze', 'Nwosu', 'Abubakar', 'Oyelaran',
            'Danjuma', 'Chukwu', 'Adebayo', 'Mohammed', 'Okonkwo', 'Lawal', 'Umeh',
        ];
    }

    private function jobTitle(string $department): string
    {
        $titles = [
            'Operations' => ['Operations Analyst', 'Operations Officer', 'Logistics Coordinator'],
            'Finance' => ['Accountant', 'Financial Analyst', 'Credit Officer'],
            'Sales' => ['Sales Executive', 'Account Manager', 'Business Development Officer'],
            'Technology' => ['Software Engineer', 'QA Engineer', 'Data Analyst'],
            'People' => ['HR Officer', 'Talent Partner', 'Learning Coordinator'],
            'Customer Support' => ['Support Agent', 'Customer Success Officer', 'Service Desk Analyst'],
        ];

        $pool = $titles[$department] ?? ['Officer'];

        return $pool[$this->nextInt(count($pool))];
    }
}
