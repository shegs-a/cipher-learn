<?php

declare(strict_types=1);

namespace App\Assignment;

use App\Enums\AssignmentBlockReason;
use App\Models\EmployeeLeave;

/**
 * The verdict of the pre-assignment eligibility check (see {@see AssignmentEligibility}).
 *
 * Carries the leave snapshot and the policy state at decision time so the caller
 * can audit, notify and report from the decision alone, without re-querying.
 */
final readonly class EligibilityDecision
{
    private function __construct(
        public bool $blocked,
        public ?AssignmentBlockReason $blockReason,
        public ?EmployeeLeave $leave,
        /** Whether the tenant currently allows assignments to people on leave. */
        public bool $policyAllowsLeave,
        /** True when the local leave data is older than the freshness threshold. */
        public bool $leaveDataStale,
    ) {}

    public static function eligible(bool $policyAllowsLeave, bool $leaveDataStale): self
    {
        return new self(false, null, null, $policyAllowsLeave, $leaveDataStale);
    }

    /** On leave, but the tenant's policy permits assigning anyway. */
    public static function allowedWhileOnLeave(EmployeeLeave $leave, bool $leaveDataStale): self
    {
        return new self(false, null, $leave, true, $leaveDataStale);
    }

    public static function blockedOnLeave(EmployeeLeave $leave, bool $leaveDataStale): self
    {
        return new self(true, AssignmentBlockReason::EmployeeOnLeave, $leave, false, $leaveDataStale);
    }

    /** On leave and permitted by policy — worth an audit trail of its own. */
    public function isAllowedWhileOnLeave(): bool
    {
        return ! $this->blocked && $this->leave !== null;
    }

    public function leaveStart(): ?string
    {
        return $this->leave?->starts_on->toDateString();
    }

    public function leaveEnd(): ?string
    {
        return $this->leave?->ends_on->toDateString();
    }
}
