<?php

declare(strict_types=1);

namespace App\Hris\Sync;

use App\Enums\EmployeeStatus;
use App\Hris\Contracts\HrisEmployeeSource;
use App\Hris\Data\EmployeeData;
use App\Hris\Exceptions\HrisConnectionException;
use App\Hris\HrisManager;
use App\Models\Employee;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Support\Tenancy;
use Throwable;

/**
 * Pulls the employee directory from a tenant's HR system into `employees`.
 *
 * The HR system is the source of truth for people: this service mirrors it, it
 * never invents anyone. Every run is recorded as a SyncRun so there is always an
 * auditable answer to "where did this person come from, and when".
 *
 * Design notes worth keeping in mind before changing anything here:
 *
 *  - **Full pull, not a delta.** Every run reads the whole directory. It costs
 *    more than a webhook/delta feed but is self-healing: a missed event can
 *    never leave us permanently out of step, and re-running is always safe.
 *  - **Idempotent.** A second run against unchanged data writes nothing and
 *    reports every record as `unchanged`. This is what makes it safe to schedule.
 *  - **Column-explicit writes.** Only HRIS-sourced columns are written (see
 *    EmployeeData::toEmployeeAttributes). Locally-owned columns — notably
 *    Sprint 3's `employees.user_id` login link — are never touched by a re-sync.
 *  - **Leavers are marked, never deleted.** Their learning history and
 *    certificates must survive them leaving the company.
 */
final class SyncEmployees
{
    public function __construct(
        private readonly HrisManager $hris,
        private readonly Tenancy $tenancy,
    ) {}

    /**
     * Run a full employee sync for one tenant.
     *
     * Everything happens inside the tenant context so the BelongsToTenant global
     * scope stamps and filters correctly — this is equally true in the console
     * and on a queue worker, where there is no authenticated user to infer it from.
     *
     * @param  bool  $force  Override the mass-exit guard (see sweepLeavers) for a
     *                       legitimate large reduction in force. Off by default.
     */
    public function forTenant(Tenant $tenant, bool $force = false): SyncRun
    {
        return $this->tenancy->runFor($tenant, function () use ($tenant, $force): SyncRun {
            $adapter = $this->hris->for($tenant);

            $run = SyncRun::create([
                'type' => 'employee_sync',
                'status' => 'running',
                'dry_run' => false,
                'started_at' => now(),
            ]);

            try {
                $stats = $this->pull($adapter, $force);

                // A tripped mass-exit guard is a completed-with-attention outcome,
                // not a crash: the upserts landed, but we withheld the destructive
                // sweep and a human must confirm it. Marking the run `failed`
                // surfaces it red in the sync history and lets the console command
                // exit non-zero so a scheduler notices.
                $run->update([
                    'status' => $stats['exit_guard_tripped'] ? 'failed' : 'completed',
                    'stats' => $stats,
                    'finished_at' => now(),
                ]);
            } catch (Throwable $e) {
                // Record the failure on the run rather than swallowing it: an
                // operator needs to see *that* the sync failed and why, and the
                // exception still propagates so a scheduled job reports non-zero.
                $run->update([
                    'status' => 'failed',
                    'stats' => ['error' => $e->getMessage()],
                    'finished_at' => now(),
                ]);

                throw $e;
            }

            return $run->refresh();
        });
    }

    /**
     * The sync itself: upsert everyone, link managers, then sweep leavers.
     *
     * @return array{created: int, updated: int, unchanged: int, exited: int, errors: int, adapter: string, exit_guard_tripped: bool, would_exit: int, active_total: int, exit_fraction: float}
     *
     * @throws HrisConnectionException
     */
    private function pull(HrisEmployeeSource $adapter, bool $force): array
    {
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $errors = 0;

        /** @var array<string, string> $managerLinks external id => manager's external id */
        $managerLinks = [];

        /** @var list<string> $seen every external id the HR system returned this run */
        $seen = [];

        // ---- Pass 1: upsert people, deferring manager links -----------------
        // Managers are linked afterwards because the directory may list a report
        // before their manager, and we cannot point at a row that does not exist.
        foreach ($adapter->fetchEmployees() as $incoming) {
            if ($incoming->externalId === '') {
                // Without a stable key we cannot match this person on a later run,
                // which would create a duplicate every sync. Skip and count it.
                $errors++;

                continue;
            }

            try {
                $outcome = $this->upsert($incoming);

                match ($outcome) {
                    'created' => $created++,
                    'updated' => $updated++,
                    default => $unchanged++,
                };

                $seen[] = $incoming->externalId;

                if ($incoming->managerExternalId !== null) {
                    $managerLinks[$incoming->externalId] = $incoming->managerExternalId;
                }
            } catch (Throwable) {
                // One malformed record must not abandon the whole directory —
                // count it and keep going. The count surfaces on the SyncRun.
                $errors++;
            }
        }

        // ---- Pass 2: resolve reporting lines --------------------------------
        $updated += $this->linkManagers($managerLinks);

        // ---- Pass 3: mark leavers (behind the mass-exit guard) --------------
        $sweep = $this->sweepLeavers($seen, $force);

        return [
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'exited' => $sweep['exited'],
            'errors' => $errors,
            'adapter' => $adapter->name(),
            // Guard telemetry, always reported so a normal run's numbers are
            // visible too (fraction well under the threshold is reassuring).
            'exit_guard_tripped' => $sweep['exit_guard_tripped'],
            'would_exit' => $sweep['would_exit'],
            'active_total' => $sweep['active_total'],
            'exit_fraction' => $sweep['exit_fraction'],
        ];
    }

    /**
     * Create or update one employee, reporting which of the two happened.
     *
     * Matches on `external_id` within the tenant — the unique key Sprint 1 put
     * on (tenant_id, external_id). Writes only HRIS-owned columns.
     *
     * @return 'created'|'updated'|'unchanged'
     */
    private function upsert(EmployeeData $incoming): string
    {
        $employee = Employee::where('external_id', $incoming->externalId)->first();
        $attributes = $incoming->toEmployeeAttributes();

        if ($employee === null) {
            Employee::create($attributes + ['external_id' => $incoming->externalId]);

            return 'created';
        }

        $employee->fill($attributes);

        // isDirty() over a blind save() so "unchanged" is a truthful count and a
        // no-op sync doesn't churn updated_at on every row in the company.
        if (! $employee->isDirty()) {
            return 'unchanged';
        }

        $employee->save();

        return 'updated';
    }

    /**
     * Point each employee at their manager, now that everyone exists.
     *
     * @param  array<string, string>  $managerLinks  external id => manager external id
     * @return int number of rows whose manager actually changed
     */
    private function linkManagers(array $managerLinks): int
    {
        if ($managerLinks === []) {
            return 0;
        }

        // One query for the whole tenant: external_id => local ULID.
        /** @var array<string, string> $idsByExternalId */
        $idsByExternalId = Employee::query()
            ->whereNotNull('external_id')
            ->pluck('id', 'external_id')
            ->all();

        $changed = 0;

        foreach ($managerLinks as $externalId => $managerExternalId) {
            $employeeId = $idsByExternalId[$externalId] ?? null;
            $managerId = $idsByExternalId[$managerExternalId] ?? null;

            // A manager the HR system references but did not return. Leave the
            // link null rather than guessing — a wrong reporting line would
            // route Sprint 6's manager view to the wrong person's data.
            if ($employeeId === null || $managerId === null || $employeeId === $managerId) {
                continue;
            }

            $changed += Employee::where('id', $employeeId)
                ->where(fn ($q) => $q->whereNull('manager_id')->orWhere('manager_id', '!=', $managerId))
                ->update(['manager_id' => $managerId]);
        }

        return $changed;
    }

    /**
     * Mark anyone the HR system no longer lists as `exited` — behind a guard.
     *
     * Scoped to employees that HAVE an `external_id` — those are the ones this
     * adapter owns. A record with a null external_id was created locally and is
     * none of the sync's business; sweeping it would silently deactivate people
     * the HR system never claimed to manage.
     *
     * THE MASS-EXIT GUARD. The sweep is destructive and it trusts the adapter's
     * directory absolutely: if a live HR API returns an empty or truncated list
     * (an auth failure that still answers 200, a paging bug, a partial outage),
     * the naive sweep would mark the ENTIRE active workforce as exited in a
     * single run — silently withdrawing everyone's training. So before applying
     * it we check the fraction of the active workforce that would leave, and if
     * that is implausibly high we WITHHOLD the sweep and flag the run instead.
     *
     * The guard is skipped when:
     *   - `$force` is set (a real, confirmed reduction in force), or
     *   - the active workforce is below `exit_guard_min_active` (on a tiny tenant
     *     one leaver is a large fraction, so the ratio is meaningless), or
     *   - nothing would be exited anyway (e.g. the first sync into a tenant).
     *
     * @param  list<string>  $seen
     * @return array{exited: int, exit_guard_tripped: bool, would_exit: int, active_total: int, exit_fraction: float}
     */
    private function sweepLeavers(array $seen, bool $force): array
    {
        // The set the sweep would touch: active, adapter-owned, not in this run.
        $candidates = Employee::query()
            ->whereNotNull('external_id')
            ->where('status', '!=', EmployeeStatus::Exited->value)
            ->when($seen !== [], fn ($q) => $q->whereNotIn('external_id', $seen));

        $activeTotal = (int) Employee::query()
            ->whereNotNull('external_id')
            ->where('status', '!=', EmployeeStatus::Exited->value)
            ->count();

        $wouldExit = (int) (clone $candidates)->count();
        $fraction = $activeTotal > 0 ? round($wouldExit / $activeTotal, 4) : 0.0;

        $threshold = (float) config('hris.sync.exit_guard_threshold', 0.20);
        $minActive = (int) config('hris.sync.exit_guard_min_active', 10);

        $tripped = ! $force
            && $wouldExit > 0
            && $activeTotal >= $minActive
            && $fraction > $threshold;

        if ($tripped) {
            // Withhold the sweep entirely. The upserts and manager links from this
            // run stand; only the destructive step waits for a human (re-run with
            // --force, or once the HR feed is confirmed healthy).
            return [
                'exited' => 0,
                'exit_guard_tripped' => true,
                'would_exit' => $wouldExit,
                'active_total' => $activeTotal,
                'exit_fraction' => $fraction,
            ];
        }

        $exited = (int) $candidates->update(['status' => EmployeeStatus::Exited->value]);

        return [
            'exited' => $exited,
            'exit_guard_tripped' => false,
            'would_exit' => $wouldExit,
            'active_total' => $activeTotal,
            'exit_fraction' => $fraction,
        ];
    }
}
