<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Assessed outcomes: who completed (or failed) what, when, with their best score
 * and number of attempts. Filter by course, department, result and a completion
 * date range. The dashboard shows the aggregate pass rate; this is the row-level
 * detail behind it.
 */
final class CompletionsReport extends BaseReport
{
    /** The two assessed, terminal outcomes this report covers. */
    private const RESULTS = [
        EnrollmentStatus::Completed,
        EnrollmentStatus::Failed,
    ];

    public function key(): string
    {
        return 'completions';
    }

    public function label(): string
    {
        return 'Completions & pass rates';
    }

    public function columns(): array
    {
        return [
            'learner' => 'Learner',
            'department' => 'Department',
            'course' => 'Course',
            'result' => 'Result',
            'score' => 'Best score',
            'attempts' => 'Attempts',
            'completed_at' => 'Completed',
        ];
    }

    public function filters(): array
    {
        return [
            'course_id' => ['label' => 'Course', 'type' => 'select', 'options' => $this->courseOptions()],
            'department' => ['label' => 'Department', 'type' => 'select', 'options' => $this->departmentOptions()],
            'status' => ['label' => 'Result', 'type' => 'select', 'options' => $this->enumOptions(self::RESULTS)],
            'from' => ['label' => 'Completed from', 'type' => 'date'],
            'to' => ['label' => 'Completed to', 'type' => 'date'],
        ];
    }

    public function query(array $filters): Builder
    {
        $results = array_map(fn (EnrollmentStatus $s) => $s->value, self::RESULTS);

        return Enrollment::query()
            ->with(['employee', 'course'])
            ->withMax('quizAttempts', 'score')
            ->withCount('quizAttempts')
            ->whereIn('status', $results)
            ->when($this->str($filters, 'course_id'), fn (Builder $q, string $id) => $q->where('course_id', $id))
            ->when($this->str($filters, 'department'), fn (Builder $q, string $d) => $q->whereHas('employee', fn (Builder $e) => $e->where('department', $d)))
            ->when(
                in_array($this->str($filters, 'status'), $results, true) ? $this->str($filters, 'status') : null,
                fn (Builder $q, string $s) => $q->where('status', $s),
            )
            ->when($this->date($filters, 'from'), fn (Builder $q, Carbon $d) => $q->where('completed_at', '>=', $d->startOfDay()))
            ->when($this->date($filters, 'to'), fn (Builder $q, Carbon $d) => $q->where('completed_at', '<=', $d->endOfDay()))
            ->latest('completed_at');
    }

    public function row(Model $record): array
    {
        /** @var Enrollment $record */
        $best = $record->getAttribute('quiz_attempts_max_score');

        return [
            'learner' => $record->employee?->full_name,
            'department' => $record->employee?->department,
            'course' => $record->course?->title,
            'result' => $record->status->label(),
            'score' => $best !== null ? (int) $best.'%' : '—',
            'attempts' => (int) $record->getAttribute('quiz_attempts_count'),
            'completed_at' => $record->completed_at?->format('Y-m-d') ?? '—',
        ];
    }
}
