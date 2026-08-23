# Sprint 6 Plan — Admin Overview Dashboard

> Status: draft for review. **Scope reset (owner, 2026-07-31):** the automated
> performance-gap rules engine and the HRIS *performance* integration are deferred
> to **V2**. V1 is a solid, standard LMS — and its assignment-with-a-reason already
> shipped manually in Sprint 4 ("the rules engine = assignment of courses + why").
> So Sprint 6 is now just the piece a standard LMS is still missing at the top:
> the **admin overview dashboard**.

## Objective
Turn the `/admin` landing (Filament's empty default today) into an at-a-glance
operational dashboard: how many people, how much training is assigned and
completed, what's overdue or due for recertification, and what the background jobs
have been doing. Built entirely from data we already have (Sprints 2–5) — **no new
tables, no performance data, no new dependencies.**

## Why now (and why it was deferred to here)
The dashboard was always parked for this point because its metrics are only
meaningful once enrolments (Sprint 4) and completions / quiz results /
certificates (Sprint 5) exist. They now do, so every widget has real data.

## What's already in place (the data sources)
- **Employees** — `status` (active / exited), department, location (HRIS sync, S2).
- **Enrolments** — `status` (assigned / in_progress / completed / failed /
  requested / cancelled / waived), `source`, `due_at` (S4/S5).
- **Certificates** — `issued_at`, `expires_at` (recert deadline) (S5).
- **Quiz attempts** — `passed`, `score` (S5).
- **Sync runs** — the background-job audit trail (S2).
All are tenant-scoped by `BelongsToTenant`, and `BindCurrentTenant` runs on the
panel, so every widget query is automatically constrained to the current tenant.

## Delivered — Filament widgets on the dashboard
The panel already auto-discovers `app/Filament/Widgets`. Add:

**1. Headline stats row** (`StatsOverviewWidget`)
- Active headcount · Leavers (exited)
- Active enrolments (assigned + in_progress) · Overdue (open + past `due_at`)
- Completion rate (completed / all non-pending enrolments) · Pass rate (passing
  attempts / graded attempts)
- Certificates issued · Recert due ≤ 60 days
Each tile shows a small trend/description where cheap; all counts tenant-scoped.

**2. Enrolments by status** (`ChartWidget`, doughnut) — the mix at a glance
(assigned / in progress / completed / failed / overdue), so an operator sees where
learners are stuck.

**3. Completions over time** (`ChartWidget`, line/bar) — completions per week/month
over the last N periods, from `enrollments.completed_at` — the "is training
actually happening" trend.

**4. Course coverage** (`ChartWidget` or table) — top courses by active enrolment
(and completion count), so L&D sees what's actually being taken.

**5. Recent background runs** (table widget) — the latest `SyncRun`s (what ran,
when, what changed), reusing the Sprint 2 audit data — the "is the plumbing
healthy" panel.

## Access / gating
The dashboard page is the panel landing, so anyone who can reach the panel sees it.
Gate each widget by the permission its data belongs to, so a **Content
Administrator** (courses only) doesn't see people/enrolment metrics:
- headcount/leavers → `employees.view`; enrolment/completion/cert widgets →
  `enrollments.view` or `reports.view`; sync runs → `employees.view`.
A widget the viewer can't see simply doesn't render (Filament `canView`). The acid
test extends: Content Admin sees an appropriately empty/limited dashboard.

## Tests (Pest — MySQL 8 in CI)
- Each stat computes correctly against seeded data (headcount, active enrolments,
  overdue, completion & pass rates, recert-due window) and is **tenant-scoped**
  (a second tenant's records never leak in).
- Widget gating: a Content Administrator can't see the people/enrolment widgets;
  an L&D/Admin can.
- Chart datasets return the expected buckets/counts.
- The dashboard page renders for an admin without error (smoke).

## Suggested build order
1. A small `DashboardMetrics` support/service that computes the tenant-scoped
   figures once (so widgets are thin and the numbers are unit-testable directly).
2. Stats row widget + tests.
3. The three charts + coverage.
4. Recent sync runs table widget.
5. Widget gating + acid-test extension.
6. Close: browser walkthrough, isolation checks, report, PR.

## Rough size
~2–3 focused sessions. It's read-only aggregation over existing, tenant-scoped
data — the risk is entirely in getting the numbers (and their scoping) right,
which is why the metrics live in a directly-testable service. **New deps: none.**

## Open decisions (recommendation in each)
- **(a) Metrics in a testable service vs inline in widgets** → *a `DashboardMetrics`
  service* — keeps widgets thin and lets the numbers be asserted directly, without
  rendering Filament.
- **(b) Charts via Filament's built-in `ChartWidget` (Chart.js) vs a custom view**
  → *Filament `ChartWidget`* — it's already in the stack, themed, no new dep.
- **(c) "Recent runs" widget scope** → *just `SyncRun`s for now* (the only
  background job today); it gains rule/recert runs when those ship (V2/S8).

## Explicit non-goals (deferred)
Automated performance-gap rules engine + HRIS **performance** integration → **V2**
· AI recommender → later · notifications & chat → Sprint (engagement) · deploy +
scheduling → deploy sprint · per-manager scoped dashboard (this is the org-wide
admin view) → future if wanted.
