<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CourseStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CourseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $title
 * @property string|null $thumbnail_url
 * @property int $pass_mark
 * @property int|null $max_attempts
 * @property int|null $recert_months
 */
class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use Auditable, BelongsToTenant, HasFactory, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'status' => CourseStatus::class,
        ];
    }

    /** @return HasMany<Lesson, $this> */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('position');
    }

    /** @return HasOne<Quiz, $this> */
    public function quiz(): HasOne
    {
        return $this->hasOne(Quiz::class);
    }

    /**
     * The learning paths this course belongs to — the inverse of
     * {@see LearningPath::courses()}. Filament's Attach action on the path's
     * courses relation manager needs this inverse relation to resolve attachable
     * records; it also lets a course see which curricula it's part of.
     *
     * @return BelongsToMany<LearningPath, $this>
     */
    public function learningPaths(): BelongsToMany
    {
        return $this->belongsToMany(LearningPath::class, 'learning_path_course')
            ->withPivot('position');
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * Total download size across all modules, shown to learners on metered data.
     * Reads an eager-loaded `lessons_sum_file_size_bytes` when present (via
     * withSum) to avoid an N+1 on list views, falling back to the relation.
     */
    /** @return Attribute<int, never> */
    protected function totalFileSizeBytes(): Attribute
    {
        return Attribute::get(function (): int {
            $summed = $this->getAttribute('lessons_sum_file_size_bytes');

            return (int) ($summed ?? $this->lessons->sum('file_size_bytes'));
        });
    }
}
