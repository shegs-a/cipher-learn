<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * A customer organisation. The root of the tenant tree — everything else hangs
 * off `tenant_id`. Tenant itself is not tenant-scoped (there is no outer tenant
 * to scope it by).
 *
 * @property string|null $timezone IANA identifier, e.g. Africa/Lagos (null only before first save: the column defaults to UTC)
 * @property array<string, mixed>|null $settings
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    protected static function booted(): void
    {
        // The timezone drives the leave-sync slots and "today" in the leave check, so
        // a typo must fail loudly at write time instead of silently running every
        // sync at the wrong hour.
        static::saving(function (Tenant $tenant): void {
            // Not supplied: the column default (UTC) applies, as for pre-existing rows.
            if ($tenant->timezone === null) {
                return;
            }

            if (! in_array($tenant->timezone, timezone_identifiers_list(), true)) {
                throw new InvalidArgumentException("Invalid timezone [{$tenant->timezone}]; use an IANA identifier such as Africa/Lagos.");
            }
        });
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /** Settings key for the leave-assignment policy (see the Sprint 13 plan). */
    public const SETTING_ALLOW_ASSIGNMENT_ON_LEAVE = 'allow_assignment_to_employees_on_leave';

    /**
     * Whether admins may assign courses/paths to employees who are on leave.
     * Off unless the tenant has explicitly turned it on.
     */
    public function allowsAssignmentDuringLeave(): bool
    {
        return (bool) ($this->settings[self::SETTING_ALLOW_ASSIGNMENT_ON_LEAVE] ?? false);
    }

    /**
     * Midnight today in the TENANT's timezone — "today" for leave arithmetic and
     * the leave-sync slots. A tenant in Lagos and one in Nairobi are on different
     * calendar days for part of every day; this is what keeps them both right.
     */
    public function localToday(?CarbonImmutable $now = null): CarbonImmutable
    {
        return $this->localNow($now)->startOfDay();
    }

    /** The current instant expressed in the tenant's timezone. */
    public function localNow(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->setTimezone($this->timezone ?: 'UTC');
    }

    /** @return HasMany<Employee, $this> */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** @return HasMany<Course, $this> */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
