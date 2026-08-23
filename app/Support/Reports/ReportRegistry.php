<?php

declare(strict_types=1);

namespace App\Support\Reports;

/**
 * The catalogue of available reports, keyed by their machine key. The one place
 * that maps a key to a {@see Report} — the export route and the Filament pages
 * both resolve through here, so there's a single source of truth for "what
 * reports exist" and no key can reach an unregistered query.
 */
final class ReportRegistry
{
    /** @var array<string, class-string<Report>> */
    private const REPORTS = [
        'enrolments' => EnrolmentsReport::class,
        'completions' => CompletionsReport::class,
        'certificates' => CertificatesReport::class,
        'overdue' => OverdueReport::class,
    ];

    /** Resolve a report by key, or null if the key is unknown. */
    public function find(string $key): ?Report
    {
        $class = self::REPORTS[$key] ?? null;

        return $class !== null ? app($class) : null;
    }

    /**
     * All reports, in catalogue order.
     *
     * @return array<string, Report>
     */
    public function all(): array
    {
        $out = [];
        foreach (array_keys(self::REPORTS) as $key) {
            $out[$key] = $this->find($key);
        }

        return $out;
    }
}
