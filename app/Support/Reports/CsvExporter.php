<?php

declare(strict_types=1);

namespace App\Support\Reports;

use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a {@see Report} (with its filters applied) to a CSV download.
 *
 * Streamed and chunked over the query, so a large export never buffers the whole
 * result set in memory. `fputcsv` handles field escaping (commas, quotes,
 * newlines) correctly, so we never hand-roll CSV quoting. No dependency — native
 * PHP CSV; a formatted `.xlsx` exporter can be added later behind the same shape.
 */
final class CsvExporter
{
    /** How many records to load per chunk while streaming. */
    private const CHUNK = 500;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function download(Report $report, array $filters): StreamedResponse
    {
        $filename = $report->key().'-'.Carbon::now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($report, $filters): void {
            $handle = fopen('php://output', 'w');
            $this->write($handle, $report, $filters);
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * The CSV as a string — used by tests (and any caller that needs the content
     * rather than a download). Same writer as the streamed download.
     *
     * @param  array<string, mixed>  $filters
     */
    public function toString(Report $report, array $filters): string
    {
        $handle = fopen('php://temp', 'r+');
        $this->write($handle, $report, $filters);
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Write the header row, then one line per record, chunked over the query.
     *
     * @param  resource  $handle
     * @param  array<string, mixed>  $filters
     */
    private function write($handle, Report $report, array $filters): void
    {
        $columns = $report->columns();

        fputcsv($handle, array_values($columns));

        $report->query($filters)->chunk(self::CHUNK, function ($records) use ($handle, $report, $columns): void {
            foreach ($records as $record) {
                $row = $report->row($record);
                // Emit fields in the declared column order; a missing key is blank.
                fputcsv($handle, array_map(
                    fn (string $key): string => (string) ($row[$key] ?? ''),
                    array_keys($columns),
                ));
            }
        });
    }
}
