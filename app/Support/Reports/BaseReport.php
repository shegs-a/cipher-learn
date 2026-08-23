<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Models\Course;
use App\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * Shared helpers for the concrete reports: reading whitelisted filter values
 * safely, and building the common tenant-scoped filter option lists (courses,
 * departments). Keeps each report focused on its own query and row mapping.
 */
abstract class BaseReport implements Report
{
    /**
     * Read a non-empty string filter value, or null (so an empty control is
     * simply ignored rather than filtering to nothing).
     *
     * @param  array<string, mixed>  $filters
     */
    protected function str(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Read an integer filter value, or null.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function int(array $filters, string $key): ?int
    {
        $value = $filters[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Read a date filter value as a Carbon, or null.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function date(array $filters, string $key): ?Carbon
    {
        $value = $this->str($filters, $key);

        return $value !== null ? Carbon::parse($value) : null;
    }

    /**
     * Course id => title, for a select filter (tenant-scoped).
     *
     * @return array<string, string>
     */
    protected function courseOptions(): array
    {
        return Course::query()->orderBy('title')->pluck('title', 'id')->all();
    }

    /**
     * Distinct department names, for a select filter.
     *
     * @return array<string, string>
     */
    protected function departmentOptions(): array
    {
        return Employee::query()
            ->whereNotNull('department')
            ->distinct()
            ->orderBy('department')
            ->pluck('department', 'department')
            ->all();
    }

    /**
     * Turn a backed-enum's cases into value => label options.
     *
     * @param  list<\BackedEnum&\UnitEnum>  $cases
     * @return array<string, string>
     */
    protected function enumOptions(array $cases): array
    {
        $out = [];
        foreach ($cases as $case) {
            /** @var string $value */
            $value = $case->value;
            $out[$value] = method_exists($case, 'label') ? $case->label() : $value;
        }

        return $out;
    }
}
