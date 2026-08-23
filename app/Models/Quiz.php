<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\QuizFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int|null $pass_mark
 * @property int|null $max_attempts
 */
class Quiz extends Model
{
    /** @use HasFactory<QuizFactory> */
    use Auditable, BelongsToTenant, HasFactory, HasUlids;

    protected $table = 'quizzes';

    protected $guarded = [];

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return HasMany<Question, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position');
    }

    /** Effective pass mark: the quiz override, else the course default. */
    public function effectivePassMark(): int
    {
        return $this->pass_mark ?? $this->course->pass_mark;
    }

    /**
     * Effective attempt ceiling: the quiz override, else the course's, else a
     * sane default of 3. An assessment with unlimited tries isn't really an
     * assessment, and the "exhausted attempts → failed" rule needs a finite
     * ceiling — so a null at both levels resolves to 3 rather than infinity.
     */
    public function effectiveMaxAttempts(): int
    {
        return $this->max_attempts ?? $this->course->max_attempts ?? 3;
    }
}
