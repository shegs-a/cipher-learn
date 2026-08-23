<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Audit\Auditor;
use App\Support\Reports\CsvExporter;
use App\Support\Reports\ReportRegistry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a report to a CSV download for the signed-in tenant admin.
 *
 * Authed + tenant-bound (the web middleware sets the tenant from the user), and
 * gated by `reports.view` — the same permission as the Reports pages. The report
 * is resolved from the registry by key (an unknown key 404s), and only the
 * filters that report whitelists in its `query()` have any effect, so the query
 * stays tenant-scoped and safe regardless of what's in the query string.
 */
final class ReportExportController extends Controller
{
    public function export(Request $request, string $key, ReportRegistry $registry, CsvExporter $exporter, Auditor $auditor): StreamedResponse
    {
        abort_unless($request->user()?->can('reports.view') ?? false, 403);

        $report = $registry->find($key);
        abort_if($report === null, 404);

        /** @var array<string, mixed> $filters */
        $filters = $request->query();

        // A CSV export is a bulk read of tenant data leaving the system — a
        // data-access event worth recording, with the report and the filters that
        // shaped it. (No row is written, so nothing else would capture this.)
        $auditor->log('report.exported', extraContext: ['report' => $key, 'filters' => $filters]);

        return $exporter->download($report, $filters);
    }
}
