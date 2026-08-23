<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Models\Certificate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Issued certificates and their recertification status — so HR can see who is
 * certified and chase renewals before they lapse. Filter by course, department,
 * an "expiring within" window, and an issue-date range.
 */
final class CertificatesReport extends BaseReport
{
    public function key(): string
    {
        return 'certificates';
    }

    public function label(): string
    {
        return 'Certificates & recertification';
    }

    public function columns(): array
    {
        return [
            'holder' => 'Holder',
            'department' => 'Department',
            'course' => 'Course',
            'serial' => 'Serial',
            'issued_at' => 'Issued',
            'expires_at' => 'Expires',
            'status' => 'Status',
        ];
    }

    public function filters(): array
    {
        return [
            'course_id' => ['label' => 'Course', 'type' => 'select', 'options' => $this->courseOptions()],
            'department' => ['label' => 'Department', 'type' => 'select', 'options' => $this->departmentOptions()],
            'expiring_within' => ['label' => 'Expiring within', 'type' => 'select', 'options' => [
                '30' => '30 days', '60' => '60 days', '90' => '90 days',
            ]],
            'from' => ['label' => 'Issued from', 'type' => 'date'],
            'to' => ['label' => 'Issued to', 'type' => 'date'],
        ];
    }

    public function query(array $filters): Builder
    {
        return Certificate::query()
            ->with(['employee', 'course'])
            ->when($this->str($filters, 'course_id'), fn (Builder $q, string $id) => $q->where('course_id', $id))
            ->when($this->str($filters, 'department'), fn (Builder $q, string $d) => $q->whereHas('employee', fn (Builder $e) => $e->where('department', $d)))
            ->when($this->int($filters, 'expiring_within'), fn (Builder $q, int $days) => $q
                ->whereNotNull('expires_at')
                ->whereBetween('expires_at', [Carbon::now(), Carbon::now()->addDays($days)]))
            ->when($this->date($filters, 'from'), fn (Builder $q, Carbon $d) => $q->where('issued_at', '>=', $d->startOfDay()))
            ->when($this->date($filters, 'to'), fn (Builder $q, Carbon $d) => $q->where('issued_at', '<=', $d->endOfDay()))
            ->latest('issued_at');
    }

    public function row(Model $record): array
    {
        /** @var Certificate $record */
        return [
            'holder' => $record->employee?->full_name,
            'department' => $record->employee?->department,
            'course' => $record->course?->title,
            'serial' => $record->serial,
            'issued_at' => $record->issued_at->format('Y-m-d'),
            'expires_at' => $record->expires_at?->format('Y-m-d') ?? '—',
            'status' => $this->status($record),
        ];
    }

    /** Valid / Recert due (≤ 60 days) / Expired / No expiry. */
    private function status(Certificate $certificate): string
    {
        if ($certificate->expires_at === null) {
            return 'No expiry';
        }

        if ($certificate->isExpired()) {
            return 'Expired';
        }

        return $certificate->expires_at->lte(Carbon::now()->addDays(60)) ? 'Recert due' : 'Valid';
    }
}
