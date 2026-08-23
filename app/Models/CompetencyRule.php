<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CompetencyRuleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetencyRule extends Model
{
    /** @use HasFactory<CompetencyRuleFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'attainment_threshold' => 'decimal:2',
            'is_active' => 'boolean',
            'due_days' => 'integer',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function targetCourse(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'target_course_id');
    }
}
