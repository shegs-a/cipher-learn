<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Models\Concerns\Auditable;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The one writer of the audit trail. Everything else — the {@see Auditable}
 * trait and explicit domain-event calls — goes through here, so actor/context
 * resolution, secret redaction and the per-tenant hash chain live in a single,
 * tested place.
 *
 * Appends are serialised per tenant with a lock so the `sequence` is gapless and
 * each entry chains onto the true previous entry's hash — even under concurrency.
 */
final class Auditor
{
    /** Attribute names whose values must never enter the audit trail. */
    private const REDACT = [
        'password', 'remember_token', 'correct_keys', 'settings',
        'token', 'api_token', 'secret', 'secret_key', 'access_token',
    ];

    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * Append an audit entry. Returns the written row.
     *
     * @param  array<string, mixed>|null  $oldValues  prior attribute values (redacted)
     * @param  array<string, mixed>|null  $newValues  new attribute values (redacted)
     * @param  array<string, mixed>|null  $extraContext  merged into the request context
     */
    public function log(
        string $event,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $extraContext = null,
    ): AuditLog {
        $tenantId = $this->tenancy->id();
        $actor = $this->resolveActor();
        $context = $this->resolveContext($extraContext);

        // Serialise appends per tenant so the sequence/hash chain stays consistent.
        $lock = Cache::lock('audit-chain:'.($tenantId ?? 'system'), 10);

        return $lock->block(10, function () use ($event, $auditable, $oldValues, $newValues, $tenantId, $actor, $context): AuditLog {
            // The current tip of this tenant's chain (null tenant = its own chain).
            $tip = fn () => AuditLog::query()
                ->withoutGlobalScopes()
                ->when(
                    $tenantId === null,
                    fn ($q) => $q->whereNull('tenant_id'),
                    fn ($q) => $q->where('tenant_id', $tenantId),
                );

            $lastSequence = (int) ($tip()->max('sequence') ?? 0);
            $previousHash = $tip()->orderByDesc('sequence')->value('hash');

            $fields = [
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'sequence' => $lastSequence + 1,
                'event' => $event,
                'auditable_type' => $auditable?->getMorphClass(),
                'auditable_id' => $auditable?->getKey(),
                'actor_type' => $actor['type'],
                'actor_id' => $actor['id'],
                'actor_label' => $actor['label'],
                'actor_roles' => $actor['roles'],
                'old_values' => $oldValues !== null ? $this->redact($oldValues) : null,
                'new_values' => $newValues !== null ? $this->redact($newValues) : null,
                'context' => $context,
                'created_at' => Carbon::now()->format('Y-m-d H:i:s'),
                'previous_hash' => $previousHash,
            ];

            $fields['hash'] = AuditLog::hashFor($fields);

            return AuditLog::query()->withoutGlobalScopes()->create($fields);
        });
    }

    /**
     * Who is acting — the authenticated user, or the system (console / unauthenticated).
     *
     * @return array{type: string|null, id: string|null, label: string|null, roles: list<string>|null}
     */
    private function resolveActor(): array
    {
        $user = auth()->user();

        if ($user instanceof User) {
            return [
                'type' => $user->getMorphClass(),
                'id' => (string) $user->getKey(),
                'label' => $user->name,
                'roles' => $user->getRoleNames()->values()->all(),
            ];
        }

        return [
            'type' => null,
            'id' => null,
            'label' => app()->runningInConsole() ? 'system (console)' : 'system',
            'roles' => null,
        ];
    }

    /**
     * Request context (ip / user-agent / request-id / url / method), merged with any
     * caller-supplied extras.
     *
     * @param  array<string, mixed>|null  $extra
     * @return array<string, mixed>
     */
    private function resolveContext(?array $extra): array
    {
        $context = [];

        if (! app()->runningInConsole()) {
            $request = request();
            $context = [
                'ip' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
                'request_id' => $request->headers->get('X-Request-Id'),
                'url' => $request->fullUrl(),
                'method' => $request->method(),
            ];
        } else {
            $context = ['channel' => 'console'];
        }

        return array_merge($context, $extra ?? []);
    }

    /**
     * Replace the value of any sensitive attribute with a placeholder, recursively,
     * so passwords/tokens/HRIS credentials never land in the trail.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function redact(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACT, true)) {
                $out[$key] = '[redacted]';

                continue;
            }

            $out[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $out;
    }
}
