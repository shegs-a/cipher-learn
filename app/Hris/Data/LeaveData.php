<?php

declare(strict_types=1);

namespace App\Hris\Data;

use App\Hris\Contracts\HrisLeaveSource;
use Carbon\CarbonImmutable;

/**
 * One approved leave period for an employee, in OUR vocabulary — the neutral
 * currency of {@see HrisLeaveSource}. Vendor field names and
 * leave-type codes never leak past the adapter.
 *
 * Dates are calendar dates (no time, no zone): "on leave from 28 Sep to 7 Oct" is
 * a statement about the client's calendar, evaluated in the tenant's timezone.
 */
final readonly class LeaveData
{
    public function __construct(
        /** The employee's HRIS id — resolved to a local employee by the sync. */
        public string $employeeExternalId,
        /** Stable id of this leave record in the HRIS; the upsert key. */
        public string $leaveExternalId,
        public CarbonImmutable $startsOn,
        public CarbonImmutable $endsOn,
        /** Leave type/reason where the HRIS provides one (e.g. "Annual"). */
        public ?string $type = null,
        /**
         * The HRIS's own verdict on "is this person on leave right now", when it
         * has one. Null when the vendor offers no such flag. It takes precedence
         * over date arithmetic while fresh (see EmployeeAvailability).
         */
        public ?bool $isCurrent = null,
    ) {}
}
