<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Audit\Auditor;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One entry in the tamper-evident audit trail.
 *
 * **Append-only:** updates and deletes are blocked at the application layer (they
 * throw), so history can't be quietly rewritten by app code. Each row is also
 * linked into a **per-tenant hash chain** — `hash = sha256(canonical(row) ‖
 * previous_hash)` — so any edit, deletion or insertion is detectable by
 * re-walking the chain ({@see self::computeHash()} / the `audit:verify` command).
 *
 * Rows are written only through {@see Auditor}, which sets every
 * field explicitly (id, created_at, sequence, hash) so what is hashed is exactly
 * what is stored.
 *
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property array<string, mixed>|null $context
 * @property list<string>|null $actor_roles
 * @property int $sequence
 * @property Carbon|null $created_at
 */
class AuditLog extends Model
{
    use BelongsToTenant, HasUlids;

    /** No updated_at — an audit row never changes. */
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'actor_roles' => 'array',
            'old_values' => 'array',
            'new_values' => 'array',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Append-only: the trail can only ever grow.
        static::updating(function (): void {
            throw new RuntimeException('Audit logs are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('Audit logs are append-only and cannot be deleted.');
        });
    }

    /** @return MorphTo<Model, $this> */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * The canonical hash of a row's fields chained onto `previous_hash`. The single
     * source of truth for both writing (the Auditor) and verification, so they can
     * never disagree. Field order is fixed; JSON payloads are recursively
     * key-sorted so a re-decoded value hashes identically regardless of storage.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function hashFor(array $fields): string
    {
        $ordered = [
            'id' => $fields['id'] ?? null,
            'tenant_id' => $fields['tenant_id'] ?? null,
            'sequence' => (int) ($fields['sequence'] ?? 0),
            'event' => $fields['event'] ?? null,
            'auditable_type' => $fields['auditable_type'] ?? null,
            'auditable_id' => $fields['auditable_id'] ?? null,
            'actor_type' => $fields['actor_type'] ?? null,
            'actor_id' => $fields['actor_id'] ?? null,
            'actor_label' => $fields['actor_label'] ?? null,
            'actor_roles' => self::sortDeep($fields['actor_roles'] ?? null),
            'old_values' => self::sortDeep($fields['old_values'] ?? null),
            'new_values' => self::sortDeep($fields['new_values'] ?? null),
            'context' => self::sortDeep($fields['context'] ?? null),
            'created_at' => $fields['created_at'] ?? null,
            'previous_hash' => $fields['previous_hash'] ?? null,
        ];

        return hash('sha256', (string) json_encode($ordered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** Recompute this stored row's hash from its own columns (for verification). */
    public function computeHash(): string
    {
        return self::hashFor([
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'sequence' => $this->sequence,
            'event' => $this->event,
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_id,
            'actor_label' => $this->actor_label,
            'actor_roles' => $this->actor_roles,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'context' => $this->context,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'previous_hash' => $this->previous_hash,
        ]);
    }

    /** Whether this row's stored hash matches its recomputed hash. */
    public function hasValidHash(): bool
    {
        return hash_equals($this->hash, $this->computeHash());
    }

    /**
     * Recursively sort array keys so a value hashes the same however its keys were
     * ordered when stored/decoded. Non-arrays pass through unchanged.
     */
    private static function sortDeep(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sorted = [];
        foreach ($value as $k => $v) {
            $sorted[$k] = self::sortDeep($v);
        }
        ksort($sorted);

        return $sorted;
    }
}
