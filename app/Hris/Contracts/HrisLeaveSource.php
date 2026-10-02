<?php

declare(strict_types=1);

namespace App\Hris\Contracts;

use App\Hris\Data\LeaveData;
use App\Hris\Exceptions\HrisConnectionException;
use App\Hris\Exceptions\HrisUnsupportedOperation;
use Carbon\CarbonImmutable;

/**
 * The leave side of the HRIS port: who is (or will be) away, and when.
 *
 * Another deliberately narrow interface, in the same spirit as
 * {@see HrisEmployeeSource} and {@see HrisWriteback}. Only the leave sync depends
 * on it, and — crucially — the assignment path does NOT: assignment reads the
 * local `employee_leaves` table that the sync fills, so an HR outage or a slow
 * vendor API can never block anyone from assigning training.
 *
 * Not every HR system exposes leave. {@see supportsLeave()} lets the sync skip
 * those tenants cleanly instead of discovering the gap by catching an exception.
 */
interface HrisLeaveSource
{
    /** The adapter's config key (e.g. 'mock'); used in sync-run stats and logs. */
    public function name(): string;

    /**
     * Whether this adapter can genuinely report leave.
     */
    public function supportsLeave(): bool;

    /**
     * Approved leave overlapping the window `[$from, $to]` (inclusive, dates only).
     *
     * Returns an iterable so an adapter may stream pages. Callers treat it as
     * traversable-once. Only APPROVED leave belongs here — pending or rejected
     * requests are not leave yet.
     *
     * @return iterable<int, LeaveData>
     *
     * @throws HrisUnsupportedOperation when {@see supportsLeave()} is false.
     * @throws HrisConnectionException when the HR system cannot be reached.
     */
    public function fetchLeave(CarbonImmutable $from, CarbonImmutable $to): iterable;
}
