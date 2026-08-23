<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Audit\Auditor;
use Illuminate\Database\Eloquent\Model;

/**
 * Drop onto a model to automatically record its writes to the tamper-evident audit
 * trail: `created` / `updated` / `deleted` become `{model}.created` etc., each
 * carrying the affected attributes (an update logs only the changed ones, old →
 * new). Hidden attributes are excluded from snapshots and the {@see Auditor}
 * further redacts sensitive keys, so nothing secret is recorded.
 *
 * All writing goes through the Auditor, so the per-tenant hash chain and actor/
 * context resolution stay in one place.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            app(Auditor::class)->log(
                static::auditEvent('created'),
                $model,
                newValues: $model->attributesToArray(),
            );
        });

        static::updated(function (Model $model): void {
            $changes = $model->getChanges();
            unset($changes['updated_at']); // touch noise, not a real change

            if ($changes === []) {
                return;
            }

            $old = [];
            foreach (array_keys($changes) as $key) {
                $old[$key] = $model->getOriginal($key);
            }

            app(Auditor::class)->log(
                static::auditEvent('updated'),
                $model,
                oldValues: $old,
                newValues: $changes,
            );
        });

        static::deleted(function (Model $model): void {
            app(Auditor::class)->log(
                static::auditEvent('deleted'),
                $model,
                oldValues: $model->attributesToArray(),
            );
        });
    }

    /** e.g. `enrollment.updated`, `certificate.created`. */
    protected static function auditEvent(string $verb): string
    {
        return str(class_basename(static::class))->snake()->toString().'.'.$verb;
    }
}
