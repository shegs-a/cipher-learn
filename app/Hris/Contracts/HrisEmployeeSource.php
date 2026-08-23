<?php

declare(strict_types=1);

namespace App\Hris\Contracts;

use App\Hris\Data\EmployeeData;
use App\Hris\Exceptions\HrisConnectionException;

/**
 * The read side of the HRIS port: somewhere we can get people from.
 *
 * Kept deliberately separate from {@see HrisWriteback} (interface segregation).
 * The employee sync depends on THIS interface only — it has no business being
 * able to push data back, and typing it narrowly makes that structural rather
 * than a matter of discipline. Sprint 4's assignment engine will add a third,
 * equally narrow `HrisPerformanceSource` without disturbing either of these.
 */
interface HrisEmployeeSource
{
    /**
     * Every employee the HR system knows about for the current tenant.
     *
     * Returns an iterable (not an array) so an adapter is free to stream or page
     * through a large org without materialising it all in memory; the Mock just
     * yields from a generated set. Callers must treat it as traversable-once.
     *
     * @return iterable<int, EmployeeData>
     *
     * @throws HrisConnectionException when the HR system cannot be reached.
     */
    public function fetchEmployees(): iterable;

    /**
     * A single employee by their HRIS identifier, or null when unknown.
     *
     * @throws HrisConnectionException when the HR system cannot be reached.
     */
    public function fetchEmployee(string $externalId): ?EmployeeData;

    /**
     * The adapter's config key (e.g. 'mock', 'example').
     *
     * Used in log lines, SyncRun stats and exception messages so it is always
     * clear which HR system produced a given outcome.
     */
    public function name(): string;
}
