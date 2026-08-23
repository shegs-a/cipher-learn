# Sprint 2 Plan — HRIS Port + Employee Sync

> Status: draft for review. Sprint 1 (schema hardening, PR #4) is merged to `main`.

## Objective
Stand up a **generic HRIS port** with a Mock (default) and an ExampleHR
implementation, and a **full-pull employee sync** that populates `employees`
idempotently, tenant-scoped, with a `SyncRun` audit trail. No performance data,
no assignment logic, no write-back yet — those are Sprints 4/6.

**The HR system is the source of truth for people.** Employees feed *into* the
LMS from the HRIS; the LMS never invents them. Everything downstream — enrollments,
the learner's identity (Sprint 3), performance-driven assignment (Sprint 4) —
hangs off records this sync owns. That ownership principle drives two design
choices below: the exit sweep (leavers marked, never deleted) and the **read-only**
Filament Employees resource (no manual employee creation, because HR owns them).

## What Sprint 1 already left in place
- `tenants.hris_adapter` (default `'mock'`) + `tenants.settings` JSON — per-tenant
  adapter selection + config, no migration needed to configure.
- `employees.external_id` with `unique(tenant_id, external_id)` — a clean upsert key.
- `EmployeeStatus` enum (`active | exited`) — leavers are marked, never deleted.
- `sync_runs` table with `type` / `status` / `dry_run` / `stats` (JSON) /
  `started_at` / `finished_at` — the audit trail.
- No `app/Hris`, no `config/hris.php`, no console commands yet — clean build.

## Architecture — the port
Interface-segregated so the sync depends only on what it uses:

```
app/Hris/
  Contracts/
    HrisEmployeeSource.php      // fetchEmployees(): iterable<EmployeeData>; fetchEmployee(string $externalId): ?EmployeeData
    HrisWriteback.php           // pushTrainingCompletion(TrainingCompletionData): void
  Data/
    EmployeeData.php            // readonly DTO — neutral, no ExampleHR-specific fields
    TrainingCompletionData.php  // readonly DTO (defined now, consumed in Sprint 6)
  Adapters/
    MockHrisAdapter.php         // implements both
    ExampleHrAdapter.php       // implements both
  HrisManager.php               // resolves the adapter for a given Tenant
  Exceptions/
    HrisUnsupportedOperation.php
    HrisConnectionException.php
app/Console/Commands/SyncEmployeesCommand.php
app/Providers/HrisServiceProvider.php
config/hris.php
```

**Why segregated interfaces:** the sync service type-hints `HrisEmployeeSource`
and never sees write-back; Sprint 4's assignment engine adds a separate
`HrisPerformanceSource` without touching this contract. Trade-off vs. one fat
`HrisAdapter` interface: two files instead of one, in exchange for not re-editing
the contract every sprint. **Recommendation: segregated.**

**DTOs as plain PHP 8.3 `readonly` classes** — not spatie/laravel-data. Keeps the
fixed stack unchanged (no new dep). `EmployeeData` carries: `externalId`,
`firstName`, `lastName`, `email`, `department`, `jobTitle`, `location`,
`managerExternalId`, `status`. Deliberately neutral — mapping from vendor JSON
happens *inside* each adapter, so nothing vendor-shaped leaks past the port.

## The adapters
- **`MockHrisAdapter` (default)** — generates **~45 deterministic employees**
  (seeded faker) as a realistic org tree: a handful of departments, managers
  referenced by `managerExternalId`, a couple of leavers. Deterministic so the
  demo and tests are stable across runs. The workhorse for Sprints 2–8.
- **`ExampleHrAdapter`** — HTTP client (`Http::` with base URL + timeout from
  `config/hris.php`, bearer token from `tenant.settings`), mapping the
  **documented public** employee shape → `EmployeeData`. **IP rule honored:**
  only fields/endpoints from docs.example.com; anything undocumented is a
  `// TODO: not in public docs` stub, never invented. `pushTrainingCompletion()`
  **throws `HrisUnsupportedOperation`** — ExampleHR has no training-completion
  endpoint. Gated behind config and *not* run against a live tenant, so it ships
  **structurally complete but unproven against live** — flagged at sprint close.
- Mock's `pushTrainingCompletion()` is a **no-op that succeeds** (so Sprint 6's
  outbox has a working default); ExampleHR throws. That asymmetry is the point
  of the port.

## Adapter resolution
`HrisManager::for(Tenant $t): HrisEmployeeSource&HrisWriteback` reads
`$t->hris_adapter` → looks up the class in `config/hris.php` → hydrates with
`$t->settings`. Registered in `HrisServiceProvider`. `config/hris.php` holds the
key→class map, default base URLs, and HTTP timeouts.

## Employee sync service — `app/Hris/Sync/SyncEmployees.php`
Runs inside `Tenancy::runFor($tenant)`:
1. Open a `SyncRun` (`type=employee_sync, status=running, started_at=now`).
2. Stream `adapter->fetchEmployees()`, **upsert on `(tenant_id, external_id)`**.
3. **Two-pass manager linking:** upsert everyone with `manager_id=null` first,
   then resolve `managerExternalId → employee.id` in a second pass (a manager may
   stream in after their report).
4. **Exit sweep (behind the mass-exit guard):** any employee **with a non-null
   `external_id`** not seen in this run → `status=exited` (never deleted; preserves
   history). Employees with null `external_id` (manually created) are left
   untouched — they aren't adapter-owned.
5. Close the `SyncRun` (`status=completed`, or `failed` if the guard tripped;
   `finished_at`, `stats={created, updated, exited, unchanged, errors, adapter,
   exit_guard_tripped, would_exit, active_total, exit_fraction}`).

- **Idempotent:** a second run reports all `unchanged`. Batched upserts (chunked),
  errors captured per-record into stats rather than aborting the whole run.

### Mass-exit guard (Sprint 2 hardening)
The exit sweep is destructive and trusts the adapter's directory absolutely. If a
live HR API returns an **empty or truncated** list (an auth failure that still
answers 200, a paging bug, a partial outage), the naive sweep would mark the
**entire active workforce** `exited` in one run — silently withdrawing everyone's
training. So before applying it, the sync computes the fraction of the active,
adapter-owned workforce that would leave, and **withholds the sweep** when that is
implausibly high — leaving everyone active and recording the run as `failed` with
`exit_guard_tripped: true` (+ `would_exit` / `active_total` / `exit_fraction`).
The upserts and manager links from the run still stand; only the destructive step
waits for a human. Config in `config/hris.php → sync`:
- `exit_guard_threshold` (default **0.20**) — trip above this leaving fraction.
- `exit_guard_min_active` (default **10**) — below this active headcount the ratio
  is meaningless (one leaver is a large fraction), so the guard is skipped.

It is also skipped when nothing would be exited (e.g. the first sync into a
tenant). Re-run `hris:sync-employees --force` to apply a genuine large reduction
in force, or once the HR feed is confirmed healthy. A tripped guard makes the
console command exit **non-zero** so a scheduler notices.

## CLI + scheduling
`php artisan hris:sync-employees {--tenant=slug|id} {--all} {--force}` → resolves
tenant(s), calls `SyncEmployees`, prints the stats table (`--force` overrides the
mass-exit guard). Registered via `routes/console.php`.
Wire a daily schedule stub in `bootstrap/app.php` (`->withSchedule(...)`) but leave
it opt-in — real cadence is a deploy concern (Sprint 8).

## Seeder integration
`DatabaseSeeder` runs one `SyncEmployees` pass for the demo tenant via the Mock
adapter, so `migrate:fresh --seed` yields the ~45 employees — gives the admin
panel and later sprints real data to work against.

## Filament surface (recommended, but severable)
Read-only **Employees** resource (list/filter by department & status, view org
tree link) + a **Sync runs** viewer with a "Sync now" action. Gives the demo
something visible. *If Sprint 2 runs hot, this slips to keep the port + sync
solid* — the one sub-scope to cut first.

## Tests (Pest — runs on **MySQL 8** in CI)
- Mock returns ~45 deterministic employees; manager tree resolves.
- Sync: creates → idempotent re-run (all unchanged) → links managers → marks a
  removed employee `exited` → writes correct `SyncRun` stats.
- **Tenant isolation:** syncing tenant A never touches tenant B's employees.
- `HrisManager` picks the class from `tenant.hris_adapter`.
- `ExampleHrAdapter`: `pushTrainingCompletion` throws `HrisUnsupportedOperation`;
  `fetchEmployees` maps a **documented JSON fixture** via `Http::fake()`.
- Null-`external_id` (manual) employees survive the exit sweep.

## Interaction with Sprint 3 (Identity / RBAC) — build so it slots cleanly
Sprint 3 (was "2.5") now sits **directly after this sprint** and adds the
authenticatable identity layer. Two forward-compat guardrails so we don't rework
the sync later:

- **Don't touch auth in Sprint 2.** Keep the existing single-admin Filament login.
  The "one `/login`", portal routing, and `spatie/laravel-permission` team scoping
  are wholly Sprint 3's — building any login/portal surface here just gets ripped out.
- **Keep the employee upsert column-explicit.** Sprint 3 adds a nullable
  `employees.user_id` linking a synced person to their login. The sync must write
  only HRIS-sourced fields, so a re-sync never clobbers that link. Enforcing this
  now (an explicit column list, not a blanket "overwrite the row") makes it a
  one-line addition in Sprint 3 instead of a refactor.
- Note for Sprint 3, not a Sprint 2 task: the tenant context this sync establishes
  across **web, Filament, console, and queue** is exactly where Sprint 3 must also
  call `setPermissionsTeamId`. Sprint 2 being the first sprint to exercise all four
  contexts is *why* RBAC slots best right after it.

## Explicit non-goals (deferred, by design)
Performance/OKR fetch → Sprint 4 · assignment rules → Sprint 4 · real
write-back/outbox → Sprint 6 · manager UI → Sprint 6 · AI → Sprint 7 · live
ExampleHR calls/creds → not this sprint (public-docs shape only) · webhook/delta
sync → later (full-pull is simpler and idempotent).

## Suggested build order
1. Exceptions + DTOs + contracts (the port surface)
2. `config/hris.php` + `HrisManager` + `HrisServiceProvider`
3. `MockHrisAdapter` (+ its determinism test)
4. `SyncEmployees` service + tests (the core)
5. `hris:sync-employees` command + seeder wiring
6. `ExampleHrAdapter` (public-docs mapping + unsupported-op) + `Http::fake` tests
7. Filament Employees + Sync-runs (if time)
8. Sprint-close: summary, run commands, flag ExampleHR-unproven-vs-live

## Rough size
~6–8 focused sessions; steps 1–5 are the load-bearing two-thirds. New deps: **none**.

## Open decisions (confirm at kickoff — recommendation in each)
- **(a)** Segregated interfaces vs. one `HrisAdapter` → *segregated*.
- **(b)** Include the Filament Employees/Sync-runs UI in-sprint vs. defer → *include, cut first if hot*.
- **(c)** Seeder auto-runs a Mock sync → *yes, nicer demo*.
