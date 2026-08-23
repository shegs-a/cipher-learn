<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnrollmentStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's membership of a learning path (see the migration for why this is a
 * linkage record, not a status machine). Path *progress* is computed live from the
 * underlying per-course enrolments, so this row never has to be kept in sync.
 */
class PathEnrollment extends Model
{
    use Auditable, BelongsToTenant, HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<LearningPath, $this> */
    public function learningPath(): BelongsTo
    {
        return $this->belongsTo(LearningPath::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    /**
     * How far the employee is through the path: completed courses ÷ courses in the
     * path, as a whole percentage. Computed from the real course enrolments, so it
     * reflects progress made even before the course was part of this path.
     */
    public function completionPercent(): int
    {
        $courseIds = $this->learningPath->courses->pluck('id');
        $total = $courseIds->count();

        if ($total === 0) {
            return 0;
        }

        $completed = Enrollment::query()
            ->where('employee_id', $this->employee_id)
            ->whereIn('course_id', $courseIds)
            ->where('status', EnrollmentStatus::Completed->value)
            ->count();

        return (int) round($completed / $total * 100);
    }
}
