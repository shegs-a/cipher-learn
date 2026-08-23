<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\LearningPathFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $name
 */
class LearningPath extends Model
{
    /** @use HasFactory<LearningPathFactory> */
    use Auditable, BelongsToTenant, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return BelongsToMany<Course, $this> */
    public function courses(): BelongsToMany
    {
        // Pivot table named explicitly: Laravel's convention would sort the model
        // names alphabetically to 'course_learning_path', but the migration uses
        // the more readable 'learning_path_course', so the relation must say so.
        return $this->belongsToMany(Course::class, 'learning_path_course')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    /** @return HasMany<PathEnrollment, $this> */
    public function pathEnrollments(): HasMany
    {
        return $this->hasMany(PathEnrollment::class);
    }
}
