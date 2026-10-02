<?php

declare(strict_types=1);

namespace App\Assignment;

use App\Hris\Sync\LeaveDataStatus;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * Answers "is this employee on leave right now?" — from the LOCAL `employee_leaves`
 * table only. It never calls the HR system: the leave sync is the only thing that
 * does, so a slow or unavailable HRIS can never hold up an assignment.
 *
 * "Today" is the tenant's own calendar day (its timezone), so someone whose leave
 * starts on the 28th is on leave from the tenant's midnight, wherever the server is.
 *
 * Precedence: if the HRIS gave an authoritative `is_current` verdict and it was
 * received TODAY (tenant-local), that wins over date arithmetic — it knows about
 * early returns and extensions. A verdict from an earlier day is ignored, because
 * it was true yesterday and may not be now; the dates decide instead.
 *
 * Instances memoise per-tenant lookups, so reuse one across a bulk run.
 */
final class EmployeeAvailability
{
    /** @var array<string, Tenant> */
    private array $tenants = [];

    /** @var array<string, bool> */
    private array $staleness = [];

    public function __construct(private readonly LeaveDataStatus $status) {}

    /** The leave covering today for this employee, or null if they are not on leave. */
    public function currentLeave(Employee $employee, ?CarbonImmutable $now = null): ?EmployeeLeave
    {
        $tenant = $this->tenantOf($employee);
        $today = $tenant->localToday($now)->toDateString();

        // A person's leave rows are few (the sync window is ~3 months), so load
        // them and decide in PHP rather than encoding the precedence rules in SQL.
        $rows = EmployeeLeave::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('employee_id', $employee->getKey())
            ->get();

        $verdicts = $rows->filter(fn (EmployeeLeave $l): bool => $l->is_current !== null
            && $l->synced_at->setTimezone($tenant->timezone)->toDateString() === $today);

        if ($verdicts->isNotEmpty()) {
            return $verdicts->first(fn (EmployeeLeave $l): bool => $l->is_current === true);
        }

        return $rows->first(fn (EmployeeLeave $l): bool => $l->starts_on->toDateString() <= $today
            && $l->ends_on->toDateString() >= $today);
    }

    /** Whether this tenant's leave data is out of date (see {@see LeaveDataStatus::isStale()}). */
    public function leaveDataIsStale(Tenant $tenant, ?CarbonImmutable $now = null): bool
    {
        if ($now !== null) {
            return $this->status->isStale($tenant, $now);
        }

        return $this->staleness[(string) $tenant->getKey()] ??= $this->status->isStale($tenant);
    }

    public function tenantOf(Employee $employee): Tenant
    {
        $id = (string) $employee->tenant_id;

        return $this->tenants[$id] ??= Tenant::query()->findOrFail($id);
    }
}
