<?php

declare(strict_types=1);

namespace App\Support\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant-scoped, filterable report over existing LMS data.
 *
 * The whole point of this interface is the feasibility note's load-bearing rule:
 * **no user-authored SQL, ever.** A report is a fixed, safe query over the
 * tenant-scoped models plus a whitelist of filters — never a free-form query
 * builder. Because the query and the row mapping live here (not in a Blade/Filament
 * view), the rows and the exported CSV can be asserted directly in tests, and the
 * on-screen table and the export are driven by the *same* query, so they can never
 * disagree.
 *
 * `query()` returns an Eloquent builder on a tenant-scoped model, so the current
 * tenant constraint is applied automatically — a report can never reach across
 * tenants, even if the author forgets a `where`.
 */
interface Report
{
    /** Stable machine key, e.g. "enrolments" — used in routes and the CSV filename. */
    public function key(): string;

    /** Human title, e.g. "Enrolments". */
    public function label(): string;

    /**
     * Column machine-key => header label, in display/export order.
     *
     * @return array<string, string>
     */
    public function columns(): array;

    /**
     * The filters this report accepts, as UI metadata. `query()` is what actually
     * applies them; this only tells the page what controls to render.
     *
     * Each entry: key => ['label' => string, 'type' => 'select'|'date',
     * 'options' => array<string,string> (for select)].
     *
     * @return array<string, array<string, mixed>>
     */
    public function filters(): array;

    /**
     * The tenant-scoped, filtered query. Reads only whitelisted keys from
     * $filters; unknown or empty values are ignored.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<covariant Model>
     */
    public function query(array $filters): Builder;

    /**
     * Map one record to its column values (keyed by the `columns()` keys). Values
     * are scalars/strings ready for a table cell or a CSV field.
     *
     * @return array<string, string|int|float|null>
     */
    public function row(Model $record): array;
}
