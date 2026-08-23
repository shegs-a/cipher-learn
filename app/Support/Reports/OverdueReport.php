<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Open enrolments that are overdue (or nearing their due date) — the "who needs
 * chasing" report, with each learner's manager so it can be actioned. Defaults to
 * strictly overdue; the window filter switches to upcoming due dates.
 */
final class OverdueReport extends BaseReport
{
    /** Only open assignments can be overdue. */
    private const OPEN = [EnrollmentStatus::Assigned, EnrollmentStatus::InProgress];

    public function key(): string
    {
        return 'overdue';
    }

    public function label(): string
    {
        return 'Overdue / at-risk';
    }

    public function columns(): array
    {
        return [
            'learner' => 'Learner',
            'department' => 'Department',
            'manager' => 'Manager',
            'course' => 'Course',
            'status' => 'Status',
            'due_at' => 'Due',
            'days_overdue' => 'Days overdue',
        ];
    }

    public function filters(): array
    {
        return [
            'course_id' => ['label' => 'Course', 'type' => 'select', 'options' => $this->courseOptions()],
            'department' => ['label' => 'Department', 'type' => 'select', 'options' => $this->departmentOptions()],
            'window' => ['label' => 'Window', 'type' => 'select', 'options' => [
                'overdue' => 'Overdue',
                'due_7' => 'Due within 7 days',
                'due_14' => 'Due within 14 days',
            ]],
        ];
    }

    public function query(array $filters): Builder
    {
        $open = array_map(fn (EnrollmentStatus $s) => $s->value, self::OPEN);

        return Enrollment::query()
            ->with(['employee.manager', 'course'])
            ->whereIn('status', $open)
            ->whereNotNull('due_at')
            ->when($this->str($filters, 'course_id'), fn (Builder $q, string $id) => $q->where('course_id', $id))
            ->when($this->str($filters, 'department'), fn (Builder $q, string $d) => $q->whereHas('employee', fn (Builder $e) => $e->where('department', $d)))
            ->tap(fn (Builder $q) => $this->applyWindow($q, $this->str($filters, 'window') ?? 'overdue'))
            ->orderBy('due_at');
    }

    /**
     * Constrain to overdue (default) or an upcoming-due window.
     *
     * @param  Builder<Enrollment>  $query
     */
    private function applyWindow(Builder $query, string $window): void
    {
        $now = Carbon::now();

        match ($window) {
            'due_7' => $query->whereBetween('due_at', [$now, $now->copy()->addDays(7)]),
            'due_14' => $query->whereBetween('due_at', [$now, $now->copy()->addDays(14)]),
            default => $query->where('due_at', '<', $now), // overdue
        };
    }

    public function row(Model $record): array
    {
        /** @var Enrollment $record */
        $due = $record->due_at;
        $daysOverdue = $due !== null && $due->isPast() ? (int) $due->diffInDays(Carbon::now()) : 0;

        return [
            'learner' => $record->employee?->full_name,
            'department' => $record->employee?->department,
            // data_get null-safely walks the (eager-loaded) manager relation.
            'manager' => data_get($record, 'employee.manager.full_name') ?? '—',
            'course' => $record->course?->title,
            'status' => $record->status->label(),
            'due_at' => $due?->format('Y-m-d'),
            'days_overdue' => $daysOverdue > 0 ? (string) $daysOverdue : '—',
        ];
    }
}
