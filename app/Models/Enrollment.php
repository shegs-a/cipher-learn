<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnrollmentSource;
use App\Enums\EnrollmentStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property EnrollmentStatus $status
 * @property EnrollmentSource $source
 * @property Carbon|null $due_at
 * @property Carbon|null $completed_at
 */
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use Auditable, BelongsToTenant, HasFactory, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source' => EnrollmentSource::class,
            'status' => EnrollmentStatus::class,
            'evidence' => 'array',
            'due_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    /** @return HasMany<LessonProgress, $this> */
    public function lessonProgress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    /** @return HasMany<QuizAttempt, $this> */
    public function quizAttempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    /** @return HasOne<Certificate, $this> */
    public function certificate(): HasOne
    {
        return $this->hasOne(Certificate::class);
    }

    public function isOverdue(): bool
    {
        return $this->status->isOpen()
            && $this->due_at !== null
            && $this->due_at->isPast();
    }

    /**
     * How far through the course this learner is, as a whole percentage of the
     * course's lessons completed. A `completed` enrolment reads 100 regardless
     * (the quiz is the gate, not the lesson count); a course with no lessons
     * reads 0 until completion.
     *
     * Prefers eager-loaded aggregates to stay N+1-free on list views: a
     * `withCount('lessons')` on the course and a loaded `lessonProgress` relation
     * are used when present, falling back to count queries otherwise.
     */
    public function completionPercent(): int
    {
        if ($this->status === EnrollmentStatus::Completed) {
            return 100;
        }

        $total = $this->course?->getAttribute('lessons_count')
            ?? $this->course?->lessons()->count()
            ?? 0;

        if ($total === 0) {
            return 0;
        }

        return (int) min(100, round($this->completedLessonCount() / $total * 100));
    }

    /** Number of this enrolment's lessons marked complete (loaded or queried). */
    public function completedLessonCount(): int
    {
        return $this->relationLoaded('lessonProgress')
            ? $this->lessonProgress->where('status', 'completed')->count()
            : $this->lessonProgress()->where('status', 'completed')->count();
    }

    /**
     * Lesson ids this learner has completed — for ticking off the course outline.
     *
     * @return Collection<int, string>
     */
    public function completedLessonIds(): Collection
    {
        $progress = $this->relationLoaded('lessonProgress')
            ? $this->lessonProgress
            : $this->lessonProgress()->get();

        return $progress->where('status', 'completed')->pluck('lesson_id');
    }
}
