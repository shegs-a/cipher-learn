<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmployeeStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property EmployeeStatus $status
 * @property string $tenant_id
 */
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use Auditable, BelongsToTenant, HasFactory, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => EmployeeStatus::class,
        ];
    }

    /** @return Attribute<string, never> */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim("{$this->first_name} {$this->last_name}"));
    }

    /**
     * The login identity provisioned for this person, if any.
     *
     * Null until someone logs in as / is provisioned for this employee. Roles
     * live on the {@see User}, so this is the bridge from a synced person to
     * what they are allowed to do.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    /** @return HasMany<Employee, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_id');
    }

    /** @return HasMany<EmployeeLeave, $this> */
    public function leaves(): HasMany
    {
        return $this->hasMany(EmployeeLeave::class);
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** @return HasMany<Certificate, $this> */
    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    /** @return HasMany<PathEnrollment, $this> */
    public function pathEnrollments(): HasMany
    {
        return $this->hasMany(PathEnrollment::class);
    }
}
