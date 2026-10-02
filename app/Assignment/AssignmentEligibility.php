<?php

declare(strict_types=1);

namespace App\Assignment;

use App\Models\Employee;
use Carbon\CarbonImmutable;

/**
 * The single place that decides whether an employee may be assigned training right
 * now. Every assignment path goes through it (via {@see LeaveGate}), so a future
 * assignment workflow inherits the policy by using the shared services — and the
 * policy can never exist only in a UI.
 *
 *   not on leave                      → eligible
 *   on leave, tenant allows it        → allowed while on leave (audited as such)
 *   on leave, tenant disallows it     → blocked (employee_on_leave)
 *
 * Reads local data only; fails open when leave data is stale (the decision carries
 * the flag so it can be audited).
 */
final class AssignmentEligibility
{
    public function __construct(private readonly EmployeeAvailability $availability) {}

    public function check(Employee $employee, ?CarbonImmutable $now = null): EligibilityDecision
    {
        $tenant = $this->availability->tenantOf($employee);
        $allowsLeave = $tenant->allowsAssignmentDuringLeave();
        $stale = $this->availability->leaveDataIsStale($tenant, $now);

        $leave = $this->availability->currentLeave($employee, $now);

        if ($leave === null) {
            return EligibilityDecision::eligible($allowsLeave, $stale);
        }

        return $allowsLeave
            ? EligibilityDecision::allowedWhileOnLeave($leave, $stale)
            : EligibilityDecision::blockedOnLeave($leave, $stale);
    }
}
