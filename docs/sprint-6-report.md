# Sprint 6 Report — Admin Overview Dashboard

**Status:** feature-complete · **174 tests** (was 162 at S5 close) · Pint + PHPStan
(L6) clean on PHP 8.3 · verified on the **live Docker stack** (MySQL 8 / Redis).

## Scope reset (owner, 2026-07-31)
Sprint 6 was originally "automated performance-gap rules engine + admin
dashboard". The owner reset it: **the performance-data integration and the
automated rules engine move to V2**; V1 is a solid, standard LMS. And its
assignment-with-a-reason already shipped **manually** in Sprint 4 — that *is*
"the rules engine = assignment of courses + why" for V1. So Sprint 6 delivered
just the remaining standard-LMS piece: the **admin overview dashboard**. No
performance/attainment data was built (the `competency_rules` table stays unused
until V2). Trade-off named at kickoff: this narrows V1's positioning from
"performance-driven" to "a solid LMS with HRIS employee sync + write-back wired",
in exchange for shipping a complete, low-risk product sooner.

## Delivered
The `/admin` landing (Filament's empty default) is now an operational dashboard,
built entirely from existing Sprint 2–5 data — **no new tables, no new deps.**

- **`App\Support\DashboardMetrics`** — the tenant-scoped aggregation, in one
  directly-testable place so widgets stay thin: headcount / leavers, active &
  overdue enrolments, completion & pass rates, certificates + recert-due,
  enrolments-by-status, completions-by-week, course coverage. Week-bucketing is
  done in PHP so sqlite (tests) and MySQL (prod) agree.
- **Widgets** (auto-discovered): a headline **stats row**, an **enrolments-by-status
  doughnut**, a **completions-by-week line**, a **top-courses bar**, and a
  **recent-sync-runs table**. The Filament promo widget was dropped from the
  now-real dashboard.
- **Gating** — aggregate widgets require `reports.view` (Manager / L&D / Tenant
  Admin); the sync-runs widget requires `employees.view`. A **Content
  Administrator** (authoring only) sees no org metrics — the acid test holds.

## Verified end-to-end (live browser + MySQL)
Signed in as the Tenant Admin against a seeded org: the dashboard renders every
widget with real numbers — headcount 42 (4 leavers), 13 active enrolments (5
overdue), 38% completion, 100% pass, the status doughnut, the 8-week completion
trend, top courses by enrolment, and the recent Employee Sync run. A Content
Administrator sees none of the org widgets (tested).

## Tests
- `DashboardMetrics`: each figure computed correctly against seeded data and
  **tenant-scoped** (a second tenant's records never leak in) — 8 tests.
- Widget gating (Content Admin hidden; L&D/Admin shown) + the dashboard page
  renders without error for an admin — 4 tests.
- 174 total (was 162); Pint + PHPStan (L6) clean.

## Run it
```bash
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/pest
docker compose up -d --build
docker compose exec app php artisan migrate:fresh --seed
# then sign in at http://localhost:8000 as admin@cipherlearn.test / password
```

## Not built (deferred, by design)
- **Automated performance-gap rules engine + HRIS performance integration** → **V2**
  (the `competency_rules` table + `EnrollmentSource::Rule` are already in place for it).
- **AI recommender** → later · **notifications & chat** → engagement sprint ·
  **deploy + scheduling** → deploy sprint.
- **Per-manager scoped dashboard** — this is the org-wide admin view; a
  manager-scoped variant is a future option if wanted.
- **Trend deltas on stat tiles** (e.g. "+3 vs last week") — a cheap later polish;
  the tiles show current figures only.
