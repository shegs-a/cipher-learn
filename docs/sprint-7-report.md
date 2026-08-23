# Sprint 7 Report — Reports & Export (Tier 1)

**Status:** feature-complete · **190 tests** (was 174 at S6 close) · Pint + PHPStan
(L6) clean on PHP 8.3 · verified on the **live Docker stack** (MySQL 8 / Redis).

Tier 1 of the "custom reports" ask: filterable, exportable operational reports for
the **tenant admin** — their own org only. (Tiers 2–3 — saved/configurable
dashboards, a full report builder — remain V2.)

## The tenant-admin distinction (owner-clarified)
"Admin" here means the **customer org's** administrator (the `Tenant Admin`/`L&D
Manager` roles), not the platform/SaaS operator (`is_platform_admin`). This is
already how the system works: every report query runs through the tenant-scoped
models, so a tenant admin only ever sees and exports their own org — enforced by
`BelongsToTenant`, not a `where` a report author might forget. A cross-tenant,
platform-operator analytics view is deliberately **out of scope** (a separate
future item).

## Delivered
- **A safe report layer** (`App\Support\Reports\`): a `Report` is a fixed,
  tenant-scoped Eloquent query plus a **whitelist of filters** — never
  user-authored SQL. The query and row-mapping live here, so the on-screen table
  and the CSV export share one query (they can't diverge) and both are asserted
  directly in tests.
- **Four reports:** **Enrolments** (course/department/status/source/date),
  **Completions & pass rates** (best score + attempts, completed/failed),
  **Certificates & recertification** (recert-window filter; Valid / Recert-due /
  Expired status), **Overdue / at-risk** (overdue by default or a due-soon window,
  with each learner's manager).
- **`CsvExporter`** — streamed, chunked CSV (native `fputcsv` escaping; no new
  dependency). `.xlsx` can be added later behind the same shape.
- **A Reports area in the panel** — four Filament pages (live-filtered tables) with
  a **Download CSV** action pointing at an authed export route. `ReportRegistry` is
  the single key→report map that both the pages and the route resolve through.

## Access / scoping
- Gated by **`reports.view`** (Tenant Admin / L&D / Manager; **not** Content
  Administrator — acid test holds), matching the Sprint 6 dashboard. If you'd rather
  reports be Tenant-Admin/L&D-only (line managers use *My Team*), tightening the gate
  is a one-liner — flagged, not blocking.
- The export route re-checks `reports.view` and is tenant-scoped in the controller;
  an unknown report key 404s.

## Verified end-to-end (live browser + tests)
Signed in as the Tenant Admin: the **Enrolments** report renders a live-filtered
table (Adaeze Okonkwo / Sales / Debt Collections / In progress; Ifeoma Danjuma /
Operations / Leading Remote Teams / Failed …) with all filters and a Download CSV
action. The export streams a filtered, **tenant-scoped** CSV (proven in tests:
right content-type + header, another tenant's rows never appear, 403 without the
permission, 404 on an unknown key).

## Tests
- Each report: correct rows for given filters, correct row mapping, and **tenant
  isolation**; `CsvExporter` header + rows + comma/quote escaping.
- Export route: streams CSV for `reports.view`, 403 otherwise, 404 unknown key,
  tenant-scoped; pages gated (Content Admin hidden).
- 190 total (was 174); Pint + PHPStan (L6) clean.

## Run it
```bash
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/pest
docker compose up -d --build
docker compose exec app php artisan migrate:fresh --seed
# sign in at http://localhost:8000 as admin@cipherlearn.test / password → Reports
```

## Not built (deferred, by design)
- **Tier 2** (saved/configurable dashboards) and **Tier 3** (full custom report
  builder / embedded BI) → V2.
- **Platform-operator cross-tenant analytics** (the SaaS company's all-orgs view)
  → separate future item.
- **`.xlsx` export**, **scheduled/emailed reports** → later.
- **Column sorting/search** on report tables — the columns are computed; add per
  report if wanted.
