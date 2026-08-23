<?php

declare(strict_types=1);

namespace App\Actions\Hris;

use App\Hris\Contracts\HrisWriteback;
use App\Hris\Data\TrainingCompletionData;
use App\Hris\Exceptions\HrisConnectionException;
use App\Hris\Exceptions\HrisUnsupportedOperation;
use App\Hris\HrisManager;
use App\Models\OutboxEvent;
use App\Models\Tenant;
use App\Support\Tenancy;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Drains due `training.completion` outbox events, pushing each through the
 * tenant's HRIS write-back adapter. The read side of the transactional outbox.
 *
 * Processing is **per tenant**: adapters are resolved from `tenants.hris_adapter`
 * and may carry per-tenant credentials, so events are grouped by tenant and each
 * group runs inside its own tenant context ({@see Tenancy::runFor}).
 *
 * The honest-capability model drives the outcomes:
 *   • adapter can't write back at all ({@see HrisWriteback::supportsWriteback()}
 *     false, e.g. ExampleHR) → **unsupported** immediately: terminal, never
 *     retried, never counted as a failure. The loop is built and provable; it
 *     simply parks until an endpoint exists.
 *   • push succeeds → **processed**.
 *   • {@see HrisUnsupportedOperation} thrown despite the guard → **unsupported**.
 *   • {@see HrisConnectionException} (transient) → back off and retry via
 *     `available_at`; past {@see MAX_ATTEMPTS} → **failed**.
 *   • no HRIS external id on the employee → **unsupported** (nothing to write to).
 *
 * Idempotent and safe to run repeatedly (a cron/queue worker calls it).
 */
final class ProcessOutbox
{
    /** Give a transient HR API a handful of tries before giving up. */
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly HrisManager $hris,
        private readonly Tenancy $tenancy,
    ) {}

    /**
     * @return array{processed: int, unsupported: int, failed: int, retried: int}
     */
    public function handle(): array
    {
        $stats = ['processed' => 0, 'unsupported' => 0, 'failed' => 0, 'retried' => 0];

        foreach ($this->tenantIdsWithDueEvents() as $tenantId) {
            $tenant = Tenant::find($tenantId);

            if ($tenant === null) {
                continue;
            }

            $this->tenancy->runFor($tenant, function () use ($tenant, &$stats): void {
                $adapter = $this->hris->for($tenant);
                $supportsWriteback = $adapter->supportsWriteback();

                // Now tenant-scoped, so this only sees this tenant's events.
                foreach ($this->dueEvents() as $event) {
                    $this->processOne($event, $adapter, $supportsWriteback, $stats);
                }
            });
        }

        return $stats;
    }

    /**
     * Distinct tenant ids that have at least one due event. Queried across
     * tenants (the global scope isn't applied outside a tenant context) so the
     * worker can sweep every tenant in one pass.
     *
     * @return list<string>
     */
    private function tenantIdsWithDueEvents(): array
    {
        return $this->dueEventsQuery()
            ->distinct()
            ->pluck('tenant_id')
            ->all();
    }

    /** @return Collection<int, OutboxEvent> */
    private function dueEvents(): Collection
    {
        return $this->dueEventsQuery()->orderBy('created_at')->get();
    }

    /** @return Builder<OutboxEvent> */
    private function dueEventsQuery(): Builder
    {
        return OutboxEvent::query()
            ->where('type', 'training.completion')
            ->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', Carbon::now()));
    }

    /**
     * @param  array{processed: int, unsupported: int, failed: int, retried: int}  $stats
     */
    private function processOne(OutboxEvent $event, HrisWriteback $adapter, bool $supportsWriteback, array &$stats): void
    {
        if (! $supportsWriteback) {
            $this->markUnsupported($event, 'HR system does not support training-completion write-back.');
            $stats['unsupported']++;

            return;
        }

        $completion = $this->toCompletion($event->payload);

        if ($completion === null) {
            $this->markUnsupported($event, 'No HRIS external id for this employee — nothing to write against.');
            $stats['unsupported']++;

            return;
        }

        try {
            $adapter->pushTrainingCompletion($completion);

            $event->forceFill([
                'status' => 'processed',
                'attempts' => $event->attempts + 1,
                'processed_at' => Carbon::now(),
                'last_error' => null,
            ])->save();
            $stats['processed']++;
        } catch (HrisUnsupportedOperation $e) {
            $event->forceFill(['attempts' => $event->attempts + 1])->save();
            $this->markUnsupported($event, $e->getMessage());
            $stats['unsupported']++;
        } catch (HrisConnectionException $e) {
            $this->handleTransientFailure($event, $e->getMessage(), $stats);
        }
    }

    /**
     * @param  array{processed: int, unsupported: int, failed: int, retried: int}  $stats
     */
    private function handleTransientFailure(OutboxEvent $event, string $error, array &$stats): void
    {
        $attempts = $event->attempts + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $event->forceFill([
                'status' => 'failed',
                'attempts' => $attempts,
                'last_error' => $error,
            ])->save();
            $stats['failed']++;

            return;
        }

        // Linear back-off — enough to ride out a brief HR API blip without
        // hammering it, and far simpler to reason about than exponential here.
        $event->forceFill([
            'status' => 'pending',
            'attempts' => $attempts,
            'available_at' => Carbon::now()->addMinutes($attempts * 5),
            'last_error' => $error,
        ])->save();
        $stats['retried']++;
    }

    private function markUnsupported(OutboxEvent $event, string $reason): void
    {
        $event->forceFill([
            'status' => 'unsupported',
            'processed_at' => Carbon::now(),
            'last_error' => $reason,
        ])->save();
    }

    /**
     * Rebuild the write-back DTO from the stored payload. Returns null when there
     * is no HRIS key to write against (a non-HRIS employee).
     *
     * @param  array<string, mixed>  $payload
     */
    private function toCompletion(array $payload): ?TrainingCompletionData
    {
        $externalId = $payload['employee_external_id'] ?? null;

        if (! is_string($externalId) || $externalId === '') {
            return null;
        }

        return new TrainingCompletionData(
            employeeExternalId: $externalId,
            courseTitle: (string) ($payload['course_title'] ?? ''),
            completedAt: new DateTimeImmutable((string) ($payload['completed_at'] ?? 'now')),
            score: isset($payload['score']) ? (int) $payload['score'] : null,
            certificateSerial: isset($payload['certificate_serial']) ? (string) $payload['certificate_serial'] : null,
        );
    }
}
