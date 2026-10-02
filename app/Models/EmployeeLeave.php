<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\EmployeeLeaveFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One approved leave period for an employee, mirrored from the HR system by the
 * leave sync. This local table — not the HRIS — is what assignment consults, so
 * the HR API being slow or down never blocks assigning training.
 *
 * @property string $employee_id
 * @property string $external_id
 * @property string|null $leave_type
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property bool|null $is_current
 * @property Carbon $synced_at
 */
class EmployeeLeave extends Model
{
    /** @use HasFactory<EmployeeLeaveFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_current' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
