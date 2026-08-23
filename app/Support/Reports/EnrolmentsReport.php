<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\EnrollmentSource;
use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Every assignment in the org: who, what course, its status/source, who assigned
 * it, and when it's due — the core operational report. Filterable by course,
 * department, status, source and an assigned-date range.
 */
final class EnrolmentsReport extends BaseReport
{
    public function key(): string
    {
        return 'enrolments';
    }

    public function label(): string
    {
        return 'Enrolments';
    }

    public function columns(): array
    {
        return [
            'learner' => 'Learner',
            'department' => 'Department',
            'course' => 'Course',
            'status' => 'Status',
            'source' => 'Source',
            'assigned_by' => 'Assigned by',
            'due_at' => 'Due',
            'assigned_at' => 'Assigned',
        ];
    }

    public function filters(): array
    {
        return [
            'course_id' => ['label' => 'Course', 'type' => 'select', 'options' => $this->courseOptions()],
            'department' => ['label' => 'Department', 'type' => 'select', 'options' => $this->departmentOptions()],
            'status' => ['label' => 'Status', 'type' => 'select', 'options' => $this->enumOptions(EnrollmentStatus::cases())],
            'source' => ['label' => 'Source', 'type' => 'select', 'options' => $this->enumOptions(EnrollmentSource::cases())],
            'from' => ['label' => 'Assigned from', 'type' => 'date'],
            'to' => ['label' => 'Assigned to', 'type' => 'date'],
        ];
    }

    public function query(array $filters): Builder
    {
        return Enrollment::query()
            ->with(['employee', 'course', 'assignedBy'])
            ->when($this->str($filters, 'course_id'), fn (Builder $q, string $id) => $q->where('course_id', $id))
            ->when($this->str($filters, 'status'), fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($this->str($filters, 'source'), fn (Builder $q, string $s) => $q->where('source', $s))
            ->when($this->str($filters, 'department'), fn (Builder $q, string $d) => $q->whereHas('employee', fn (Builder $e) => $e->where('department', $d)))
            ->when($this->date($filters, 'from'), fn (Builder $q, Carbon $d) => $q->where('created_at', '>=', $d->startOfDay()))
            ->when($this->date($filters, 'to'), fn (Builder $q, Carbon $d) => $q->where('created_at', '<=', $d->endOfDay()))
            ->latest('created_at');
    }

    public function row(Model $record): array
    {
        /** @var Enrollment $record */
        return [
            'learner' => $record->employee?->full_name,
            'department' => $record->employee?->department,
            'course' => $record->course?->title,
            'status' => $record->status->label(),
            'source' => $record->source->label(),
            'assigned_by' => $record->assignedBy?->name,
            'due_at' => $record->due_at?->format('Y-m-d'),
            'assigned_at' => $record->created_at?->format('Y-m-d'),
        ];
    }
}
