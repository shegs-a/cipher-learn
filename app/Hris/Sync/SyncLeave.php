<?php

declare(strict_types=1);

namespace App\Hris\Sync;

use App\Hris\Contracts\HrisLeaveSource;
use App\Hris\HrisManager;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\LeaveSyncFailedNotification;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Throwable;

/**
 * Mirrors a tenant's approved leave from the HR system into `employee_leaves`.
 *
 * This is the ONLY thing that talks to the HRIS about leave. Assignment reads the
 * local table this fills, so an HR outage or slow vendor API never blocks anyone
 * from assigning training.
 *
 * Same design stance as {@see SyncEmployees}: a full pull of the window rather
 * than a delta (self-healing, safe to re-run), idempotent upserts keyed on the
 * HRIS leave id, and every run recorded as a SyncRun — the status report for
 * scheduled and manual runs alike. Cancelled leave disappears because rows in the
 * window that the HRIS no longer returns are removed, behind a wipe guard so an
 * empty or truncated feed can never erase everyone's leave in one go.
 */
final class SyncLeave
{
    public const TYPE = 'leave_sync';

    /** A `running` row older than this is presumed crashed and no longer blocks. */
    private const STALE_RUNNING_MINUTES = 30;

    public function __construct(
        private readonly HrisManager $hris,
        private readonly Tenancy $tenancy,
    ) {}

    /**
     * Why a MANUAL run cannot start right now, or null when it can: another leave
     * sync is in flight, or the last manual run was too recent (cooldown).
     */
    public function manualBlockedReason(Tenant $tenant): ?string
    {
        return $this->tenancy->runFor($tenant, function (): ?string {
            if ($this->isRunning()) {
                return 'A leave sync is already running. Try again in a minute.';
            }

            $cooldown = (int) config('hris.leave.manual_cooldown_minutes', 5);
            $last = SyncRun::query()
                ->where('type', self::TYPE)
                ->where('trigger', 'manual')
                ->latest('started_at')
                ->first();

            if ($last !== null && $last->started_at->gt(now()->subMinutes($cooldown))) {
                $wait = max(1, (int) ceil(now()->diffInSeconds($last->started_at->copy()->addMinutes($cooldown), false) / 60));

                return "Leave was synced moments ago. Please wait about {$wait} minute(s) before syncing again.";
            }

            return null;
        });
    }

    /** Whether a leave sync is currently in flight for this tenant (caller supplies tenancy). */
    public function isRunning(): bool
    {
        return SyncRun::query()
            ->where('type', self::TYPE)
            ->where('status', 'running')
            ->where('started_at', '>', now()->subMinutes(self::STALE_RUNNING_MINUTES))
            ->exists();
    }

    /**
     * Sync one tenant's leave.
     *
     * @param  'scheduled'|'manual'  $trigger
     * @param  string|null  $slot  tenant-local "Y-m-d@HH:MM" for a scheduled run, so the
     *                             dispatcher never runs the same slot twice
     */
    public function forTenant(
        Tenant $tenant,
        string $trigger = 'scheduled',
        ?User $triggeredBy = null,
        ?string $slot = null,
    ): SyncRun {
        return $this->tenancy->runFor($tenant, function () use ($tenant, $trigger, $triggeredBy, $slot): SyncRun {
            $source = $this->hris->leaveSourceFor($tenant);

            $run = SyncRun::create([
                'type' => self::TYPE,
                'trigger' => $trigger,
                'triggered_by_user_id' => $triggeredBy?->getKey(),
                'slot' => $slot,
                'status' => $source === null ? 'skipped' : 'running',
                'dry_run' => false,
                'started_at' => now(),
                'finished_at' => $source === null ? now() : null,
                'stats' => $source === null
                    ? ['adapter' => $this->hris->for($tenant)->name(), 'reason' => 'The HR system does not provide leave data.']
                    : null,
            ]);

            if ($source === null) {
                return $run;
            }

            $startedAt = microtime(true);

            try {
                $stats = $this->pull($tenant, $source);
                $stats['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

                $failed = $stats['wipe_guard_tripped'];
                $run->update([
                    'status' => $failed ? 'failed' : 'completed',
                    'stats' => $stats,
                    'finished_at' => now(),
                ]);

                if ($failed) {
                    $this->alert($tenant, $run, 'The HR system returned no leave records, so existing leave was left untouched. Check the HR integration.');
                }
            } catch (Throwable $e) {
                $run->update([
                    'status' => 'failed',
                    'stats' => [
                        'adapter' => $source->name(),
                        'error' => $e->getMessage(),
                        'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    ],
                    'finished_at' => now(),
                ]);

                $this->alert($tenant, $run, $e->getMessage());

                throw $e;
            }

            return $run->refresh();
        });
    }

    /**
     * @return array{adapter: string, fetched: int, created: int, updated: int, unchanged: int, removed: int, unmatched: int, errors: int, window_from: string, window_to: string, wipe_guard_tripped: bool, would_remove: int}
     */
    private function pull(Tenant $tenant, HrisLeaveSource $source): array
    {
        $today = $tenant->localToday();
        $from = $today->subDays((int) config('hris.leave.window_past_days', 7));
        $to = $today->addDays((int) config('hris.leave.window_future_days', 90));

        /** @var array<string, string> $employeeIds external id => local id */
        $employeeIds = Employee::query()->whereNotNull('external_id')->pluck('id', 'external_id')->all();

        /** @var array<string, EmployeeLeave> $existing */
        $existing = EmployeeLeave::query()
            ->whereDate('ends_on', '>=', $from->toDateString())
            ->whereDate('starts_on', '<=', $to->toDateString())
            ->get()
            ->keyBy(fn (EmployeeLeave $l): string => $l->employee_id.'|'.$l->external_id)
            ->all();

        $fetched = $created = $updated = $unchanged = $unmatched = $errors = 0;

        /** @var list<string> $seenIds local ids of rows the HRIS confirmed this run */
        $seenIds = [];

        foreach ($source->fetchLeave($from, $to) as $leave) {
            $fetched++;

            $employeeId = $employeeIds[$leave->employeeExternalId] ?? null;

            if ($employeeId === null) {
                // Leave for someone we have not synced (yet): count it, don't guess.
                $unmatched++;

                continue;
            }

            if ($leave->leaveExternalId === '' || $leave->endsOn->lt($leave->startsOn)) {
                $errors++;

                continue;
            }

            $attributes = [
                'leave_type' => $leave->type,
                'starts_on' => $leave->startsOn->toDateString(),
                'ends_on' => $leave->endsOn->toDateString(),
                'is_current' => $leave->isCurrent,
            ];

            $row = $existing[$employeeId.'|'.$leave->leaveExternalId] ?? null;

            if ($row === null) {
                $row = EmployeeLeave::create($attributes + [
                    'employee_id' => $employeeId,
                    'external_id' => $leave->leaveExternalId,
                    'synced_at' => now(),
                ]);
                $created++;
            } else {
                $row->fill($attributes);

                if ($row->isDirty()) {
                    $row->save();
                    $updated++;
                } else {
                    $unchanged++;
                }
            }

            $seenIds[] = (string) $row->getKey();
        }

        // Cancelled leave: in-window rows the HRIS no longer returns.
        $gone = array_values(array_filter(
            array_keys($existing),
            fn (string $key): bool => ! in_array((string) $existing[$key]->getKey(), $seenIds, true),
        ));
        $wouldRemove = count($gone);

        $guardTripped = $this->wipeGuardTripped($fetched, $today);
        $removed = 0;

        if (! $guardTripped && $wouldRemove > 0) {
            $removed = (int) EmployeeLeave::query()
                ->whereIn('id', array_map(fn (string $k): string => (string) $existing[$k]->getKey(), $gone))
                ->delete();
        }

        // Stamp freshness on rows confirmed this run (new rows already carry it).
        foreach (array_chunk($seenIds, 500) as $chunk) {
            EmployeeLeave::query()->whereIn('id', $chunk)->update(['synced_at' => now()]);
        }

        return [
            'adapter' => $source->name(),
            'fetched' => $fetched,
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'removed' => $removed,
            'unmatched' => $unmatched,
            'errors' => $errors,
            'window_from' => $from->toDateString(),
            'window_to' => $to->toDateString(),
            'wipe_guard_tripped' => $guardTripped,
            'would_remove' => $wouldRemove,
        ];
    }

    /**
     * An empty feed while we hold plenty of current/upcoming leave is far more
     * likely an HR-side fault (auth that still answers 200, a paging bug) than
     * everyone's leave being cancelled at once — so withhold the removals.
     */
    private function wipeGuardTripped(int $fetched, CarbonImmutable $today): bool
    {
        if ($fetched > 0) {
            return false;
        }

        $held = EmployeeLeave::query()->whereDate('ends_on', '>=', $today->toDateString())->count();

        return $held >= (int) config('hris.leave.wipe_guard_min_rows', 5);
    }

    /**
     * Failures only: one notification to the tenant's HRIS-sync operators. Healthy
     * runs are silent — their record lives in the sync history.
     */
    private function alert(Tenant $tenant, SyncRun $run, string $message): void
    {
        try {
            $recipients = User::query()->permission('hris.sync')->get();
        } catch (PermissionDoesNotExist) {
            // A release that adds the permission has not run `permissions:sync`
            // for this tenant yet: nobody holds it, so nobody to alert. Never let
            // alerting break (or mask) the sync's own failure.
            return;
        }

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new LeaveSyncFailedNotification(
            tenantName: $tenant->name,
            trigger: $run->trigger,
            message: $message,
        ));
    }
}
