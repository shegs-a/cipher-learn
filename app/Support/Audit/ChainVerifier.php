<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\AuditLog;

/**
 * Re-walks the tamper-evident audit chain and reports whether it is intact.
 *
 * The chain's integrity rests on three invariants, all checked here in one pass
 * per tenant (rows read in `sequence` order):
 *
 *  1. **Content unchanged** — every row's stored `hash` still equals a fresh
 *     recompute from its own columns ({@see AuditLog::hasValidHash()}). Any edit
 *     to any field breaks this.
 *  2. **Gapless sequence** — sequences run 1, 2, 3, … with no gap or repeat. A
 *     deleted or inserted row shows up as a break here.
 *  3. **Linked hashes** — each row's `previous_hash` equals the prior row's
 *     `hash` (and the genesis row's is null). Because every hash folds in the
 *     previous one, editing an early row invalidates every row after it, so a
 *     tamperer would have to rewrite the entire tail — and still couldn't, without
 *     also forging rows in a way that survives all three checks at once.
 *
 * Verification is read-only and never instantiates through Eloquent events, so it
 * cannot itself mutate the trail.
 */
final class ChainVerifier
{
    /**
     * The distinct chains present in the trail, as tenant ids. `null` is the
     * platform (system / cross-tenant) chain and is included when it has rows.
     *
     * @return list<string|null>
     */
    public function chains(): array
    {
        /** @var list<string|null> $ids */
        $ids = AuditLog::query()
            ->withoutGlobalScopes()
            ->distinct()
            ->orderByRaw('tenant_id is not null, tenant_id')
            ->pluck('tenant_id')
            ->all();

        return $ids;
    }

    /**
     * Verify a single tenant's chain (pass null for the platform chain).
     */
    public function verify(?string $tenantId): ChainVerification
    {
        $expectedSequence = 1;
        $expectedPrevious = null;
        $checked = 0;

        $rows = AuditLog::query()
            ->withoutGlobalScopes()
            ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderBy('sequence')
            ->lazy();

        foreach ($rows as $row) {
            if ($row->sequence !== $expectedSequence) {
                return ChainVerification::broken(
                    $tenantId,
                    $checked,
                    $row->sequence,
                    $row->id,
                    "sequence gap: expected {$expectedSequence}, found {$row->sequence} (a row was inserted or deleted)",
                );
            }

            if ($row->previous_hash !== $expectedPrevious) {
                return ChainVerification::broken(
                    $tenantId,
                    $checked,
                    $row->sequence,
                    $row->id,
                    'broken link: previous_hash does not match the prior row (the chain was cut or reordered)',
                );
            }

            if (! $row->hasValidHash()) {
                return ChainVerification::broken(
                    $tenantId,
                    $checked,
                    $row->sequence,
                    $row->id,
                    'content tampered: the stored hash does not match a recompute of the row',
                );
            }

            $expectedPrevious = $row->hash;
            $expectedSequence++;
            $checked++;
        }

        return ChainVerification::intact($tenantId, $checked);
    }
}
