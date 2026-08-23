# Sprint 7 Plan — Reports & Export (Tier 1)

> Status: draft for review. Sprints 0–6 merged. This is **Tier 1** of the
> "custom reports" ask: filterable, exportable operational reports for the
> **tenant admin** (their own org only). Tiers 2–3 (saved/configurable dashboards,
> a full report builder) are later/V2. (Roadmap: AI recommender + deploy shift by
> one.)

## Objective
A **Reports** area in the admin panel where a Tenant Admin / L&D Manager filters
the org's training data and exports it. Everything is **tenant-scoped** — a tenant
admin only ever sees and exports their own org (enforced by `BelongsToTenant`, not
by a `where` a report author might forget). A cross-tenant, platform-operator
analytics view is explicitly **out of scope** (a separate future item).

## The four reports (owner-confirmed)
1. **Enrolments** — every assignment: learner, course, status, source, assigned-by,
   due date, filterable by course / department / status / source / date range.
2. **Completions & pass rates** — who completed what and when, with the assessment
   score and pass/fail; by course / department / date range.
3. **Certificates & recertification** — issued certificates and upcoming recert
   deadlines (expiring within a window), so HR can chase renewals.
4. **Overdue / at-risk** — open enrolments past (or nearing) their due date, by
   learner and manager.

## Design — a small, testable report layer (not raw SQL)
The load-bearing rule from the feasibility note: **no user-authored SQL, ever;**
every report runs through the tenant-scoped models. So:

- **`App\Support\Reports\Report`** interface — `key()`, `label()`, `columns()`
  (machine key → header), `filters()` (the allowed filters), and
  `query(array $filters): Builder` returning a **tenant-scoped Eloquent builder**,
  plus `row($record): array` mapping a record to its column values. One
  implementation per report above.
- Because the query + row mapping live here (not in a Blade/Filament view), the
  **rows and the CSV are asserted directly in tests** — correctness and tenant
  isolation are provable without rendering any UI.
- **`App\Support\Reports\CsvExporter`** — streams a `Report` + filters to a CSV
  download (a `StreamedResponse`, chunked over the query, so a large export
  doesn't buffer the whole result set in memory). No new dependency; native CSV.
  (`.xlsx` is a later add — owner chose CSV now.)

## Surfaces (Filament)
- A **Reports** navigation group with one page per report. Each page renders a
  filtered table (reusing Filament's table + the report's declared filters) and a
  **"Download CSV"** header action that exports the *currently-filtered* query
  through `CsvExporter`.
- Thin `ReportPage` base (Filament `Page` + table) driven by a `Report`; four small
  subclasses. Keeps the pages declarative and the logic in the tested layer.

## Access / scoping
- Gated by **`reports.view`** — held by Tenant Admin, L&D Manager, and Manager;
  **not** Content Administrator (acid test holds — an authoring-only operator gets
  no org reports). Everything tenant-scoped. This matches the Sprint 6 dashboard
  gating, so the admin's reporting surfaces are consistent.
- **Note (b):** `reports.view` currently includes a plain **line Manager**. Tier 1
  ships org-wide reports to all three roles for consistency; if you'd rather reports
  be a Tenant-Admin/L&D-only tool (line managers use *My Team*), tightening the gate
  is a one-liner — flagged, not blocking.

## Tests (Pest — MySQL 8 in CI)
- Each report's `query()` returns the right rows for given filters, and is
  **tenant-scoped** (a second tenant's records never appear).
- `row()` maps the expected column values (status labels, scores, dates).
- Filters actually filter (course, department, status, date range, recert window).
- `CsvExporter` produces a header row + one line per record with the right values,
  and escapes commas/quotes/newlines correctly.
- Report pages render for a Tenant Admin and are hidden from a Content Admin;
  export downloads a CSV (smoke).

## Suggested build order
1. `Report` interface + `CsvExporter` + the **Enrolments** report + tests (proves
   the pattern end-to-end).
2. The other three reports + tests.
3. Filament `ReportPage` base + four pages + the Download-CSV action + gating.
4. Close: browser walkthrough (filter + export), isolation checks, report, PR.

## Rough size
~2–4 focused sessions. Read-only, filtered aggregation + CSV over existing
tenant-scoped data. **New deps: none.** Risk is concentrated in filter correctness
and tenant-scoping — which is exactly why the query/row/CSV logic is in a directly
testable layer.

## Open decisions (recommendation in each)
- **(a) Report layer as a testable `Report` interface vs Filament table filters
  inline on each page** → *the testable layer* — same reasoning as `DashboardMetrics`:
  assert the numbers/rows without rendering Filament, and reuse the query for both
  the on-screen table and the CSV so they can never diverge.
- **(b) Manager audience** (above) → *gate by `reports.view` (Tenant Admin + L&D +
  Manager), consistent with the dashboard; tightening to exclude line Managers is a
  one-liner if wanted.*
- **(c) Export format** → **CSV now** (streamed, no dep), `.xlsx` later
  (owner-confirmed).

## Explicit non-goals (deferred)
Saved / configurable dashboards (Tier 2) · full custom report builder / embedded BI
(Tier 3) → V2 · **platform-operator cross-tenant analytics** (the SaaS company's
all-orgs view) → separate future item · scheduled/emailed reports → engagement/deploy
· `.xlsx` export → later.
