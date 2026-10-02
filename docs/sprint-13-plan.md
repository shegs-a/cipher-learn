# Sprint 13 Plan — Leave-aware assignment (V2 opener)

**Goal:** implement GitHub issue #1. A tenant-level policy decides whether courses and
learning paths can be assigned to employees who are currently on leave. It is
enforced in the shared domain/service layer, never only in the UI. Every assignment
path returns a structured outcome instead of a bare count or model.

## Decisions (owner-confirmed)
- **Leave data is synced, not fetched live.** The leave sync runs at **06:00 and
  18:00 in each tenant's own timezone**, plus an admin **"Sync leave now"** button.
  Assignment reads **only the local `employee_leaves` table**, never the HRIS, so an
  HR-vendor outage or peak-time slowness cannot affect assigning. Rationale: leave
  needs approval before it starts, so near-real-time is not required.
- **Per-tenant timezone** is a required column on `tenants` (IANA name), set when the
  tenant is provisioned. It drives the sync slots and "today" in the on-leave check.
- **Fail open.** If leave data is missing or stale (no successful sync in the last 13
  hours), the assignment proceeds and the audit record notes `leave_data_stale: true`.
  A broken sync never blocks L&D.
- **Sync status reporting for every run** (scheduled or manual), kept on `SyncRun` and
  shown in the admin. **Failures only** trigger a notification to tenant admins; no
  per-run emails.
- **Setting:** `allow_assignment_to_employees_on_leave` in `tenants.settings`,
  default **off**. No migration is needed for it.

## Assumptions to confirm during review
1. **Only assigner-initiated flows are gated:** an admin or manager assigning, org-wide
   or department fan-out, bulk, and *approving* a request (approval creates the
   assignment). A learner self-enrolling in a path or requesting a course is their
   own choice and is **not** blocked.
2. **Timezone provisioning:** there is no `tenant:provision` command yet (V2 backlog),
   so for now the timezone is set by the seeder, the factory and an admin edit. The
   column is NOT NULL with a default of `UTC` for backfilling existing rows; the future
   provisioning command should require it explicitly.
3. **Bulk UI:** the issue lists "bulk" as a flow, but no bulk assign action exists
   today. I add *Assign course* / *Assign learning path* bulk actions on
   `EmployeeResource` (select employees, assign). Drop this item if you want
   domain-only.

## What exists today (don't rebuild)
- `AssignCourse::handle()` is the single enrolment writer. It is idempotent on
  `(employee, course, cycle)` and never clobbers terminal rows. It returns an
  `Enrollment`.
- `AssignCourseOrgWide` fans out over active employees and returns an `int`.
- `AssignLearningPath` upserts `PathEnrollment`, fans out to `AssignCourse` with
  `notify: false`, and sends one `PathAssignedNotification`.
- **Path fan-out lives in the UI:** `LearningPathResource::assignToWorkforce` is a
  private Filament method. It must move into a domain action.
- Callers: `Team` (manager assigns), `EnrolmentsReportPage` (approve request),
  `CourseResource` (org-wide), `LearningPathResource`, `Paths` (self-enrol),
  `Catalogue` (request).
- HRIS port: `HrisEmployeeSource` and `HrisWriteback`, resolved per tenant by
  `HrisManager`. `Auditor::log()` is available, as are `SyncRun`, `Tenancy::runFor`
  and the scheduler in `bootstrap/app.php`.

## Design

### 1. HRIS leave port
- `HrisLeaveSource` (new narrow interface, segregated like the others):
  `fetchLeave(CarbonImmutable $from, CarbonImmutable $to): iterable<LeaveData>` and
  `supportsLeave(): bool`.
- `LeaveData` (readonly DTO): `employeeExternalId`, `leaveExternalId`, `type`
  (nullable), `startsOn`, `endsOn` (dates), `isCurrent` (nullable bool — the HRIS's
  authoritative status, when it has one).
- `MockHrisAdapter` generates deterministic leave (some current, some upcoming, some
  past) so the demo and tests have realistic data. `ExampleHrAdapter` returns
  `supportsLeave() === false`, which is honest because its docs publish no leave
  endpoint. The sync skips such tenants cleanly.
- `HrisManager::leaveSourceFor(Tenant)` returns the adapter as `HrisLeaveSource`.
  Adapters are still built per tenant, so there is no cross-tenant leakage.

### 2. Storage and sync
- Migration `employee_leaves`: ulid id, `tenant_id`, `employee_id`,
  `external_id` (HRIS leave id), `leave_type`, `starts_on` (date), `ends_on` (date),
  `is_current` (nullable bool), `synced_at`. Unique on
  `(tenant_id, employee_id, external_id)`. Index on
  `(tenant_id, employee_id, starts_on, ends_on)`. Uses `BelongsToTenant` and ULIDs.
- Migration: `tenants.timezone` (string, NOT NULL, default `UTC`), validated as an IANA
  identifier. `Tenant::timezone()` returns it; `Tenant::localToday()` gives the
  tenant-local date.
- `SyncLeave` action and `hris:sync-leave {--all} {--tenant=} {--slot=}` command,
  modelled on `SyncEmployees`. It fetches a window (`today - 7d` to `today + 90d`),
  upserts by external id, and deletes rows in the window that the HRIS no longer
  returns (cancelled leave). It maps employees by external id and counts unknown ones.
- **Mass-wipe guard:** like the mass-exit guard, if the HRIS returns nothing while we
  hold many future rows, abort and record the failure instead of deleting them.
- **Per-tenant slot dispatcher.** The Laravel scheduler cannot run one entry in many
  timezones, so a cheap `hris:dispatch-leave-syncs` command runs every 10 minutes. For
  each tenant whose adapter supports leave, it checks whether tenant-local time has
  passed 06:00 or 18:00 and whether that `(tenant, local date, slot)` has a run. If not,
  it syncs that tenant. Slot idempotency means no double runs, a missed tick is caught
  on the next one, and daylight-saving shifts are handled by the local-time check.
  Scheduled `withoutOverlapping()` + `onOneServer()`.
- **Status reports on every run.** `sync_runs` gains `trigger` (`scheduled`|`manual`),
  `triggered_by_user_id`, and `slot` (`2026-10-02@06:00`, scheduled only). `stats`
  carries fetched / created / updated / removed / unmatched-employee counts, duration,
  and the error message. Outcomes: `completed`, `failed`, or `skipped` (adapter has no
  leave support). They are viewable and filterable in the existing Sync Runs screen,
  and the dashboard shows "leave data last refreshed X ago".
- **Failure alerts:** a failed run (scheduled or manual) sends one
  `LeaveSyncFailedNotification` to tenant admins. Successful runs send nothing.
- **"Sync leave now"** admin action (existing sync permission) runs a manual sync via a
  queued job. It has a 5-minute cooldown and refuses to start while another leave sync
  for that tenant is running.

### 3. Eligibility
- `Tenant::allowsAssignmentDuringLeave()` reads the setting (default `false`).
- `EmployeeAvailability::currentLeave(Employee): ?LeaveData` evaluates
  `starts_on <= today <= ends_on` with tenant-local "today" (`Tenant::localToday()`),
  reading only the local table. The HRIS flag takes
  precedence when `is_current` is non-null **and** the last sync happened on the same
  tenant-local day. Otherwise it falls back to the dates, so a flag that went stale
  across midnight cannot override correct date maths.
- `AssignmentEligibility::check(Employee): EligibilityDecision` returns
  `eligible | allowedWhileOnLeave | blocked(EmployeeOnLeave)`. It carries the leave
  snapshot, the policy state and a `dataStale` flag.
- Ordering inside an assignment: resolve employee → *would this create or transition
  to an assignment?* (if not, it is a skip) → eligibility → write. An employee who is
  already enrolled and on leave is therefore reported as **Skipped — already
  assigned**, not Blocked. A request being approved is a real transition, so the
  check applies.

### 4. Structured results
- Enums: `AssignmentOutcome` (Assigned, Blocked, Skipped), `AssignmentBlockReason`
  (EmployeeOnLeave → machine value `employee_on_leave`), `AssignmentSkipReason`
  (AlreadyAssigned, TerminalStatus, InvalidEmployee, Other).
- `AssignmentItemResult`: employee, outcome, block/skip reason, leave start/end, the
  enrollment or path membership if any, and an `assignedWhileOnLeave` flag.
- `AssignmentResult` aggregate: `total`, `assigned`, `blocked`, `skipped` (split into
  already-assigned and other), `blockedEmployees[]`, `skippedEmployees[]`, and a
  `summary()` string such as "131 assigned · 12 blocked — on leave · 5 skipped —
  already assigned".
- **Breaking change:** `AssignCourse::handle()` now returns `AssignmentItemResult`
  (the enrollment is `->enrollment`). The 6 callers and the existing tests that call
  it (listed under Tests) are updated mechanically. Existing assertions on
  enrollments, notifications and idempotency must pass unchanged in meaning.

### 5. Flows
- `AssignCourse` gets the eligibility gate. A blocked assignment creates no
  enrolment, sends no learner notification and writes the audit row.
- `AssignLearningPath` checks **before** `PathEnrollment::firstOrCreate`. Blocked
  means no membership, no per-course enrolments and no `PathAssignedNotification`.
  There is no partial assignment.
- **Bulk runners** (new): `AssignCourseToEmployees` and `AssignLearningPathToEmployees`
  take a collection of employees, continue past blocked or skipped ones, and return an
  `AssignmentResult`. One operation sends one admin summary.
- `AssignCourseOrgWide` (course) and a new `AssignLearningPathOrgWide` (extracted from
  `LearningPathResource`) resolve the segment (all active, or one department) and
  delegate to the bulk runners. Both return `AssignmentResult`.
- UIs render the result. Filament shows a toast with the counts and a persistent
  warning when anything was blocked. `Team` shows an inline error for a blocked
  individual assignment. The approve action shows a toast.

### 6. Notifications
- `AssignmentBlockedNotification` (single): employee name, type (course or path),
  title, reason, leave dates, "your organisation's policy does not allow assignments to
  employees on leave".
- `BulkAssignmentBlockedNotification` (one per bulk operation): "12 of 143 employees
  could not be assigned…", with the detailed list available from the audit record.
- Both are queued, in-app + mail, like the existing notifications, and go to
  `assignedBy`. A system-initiated run with no initiator writes the audit row only.
- The employee is never notified of a blocked assignment. Successful-assignment
  notifications are unchanged.

### 7. Audit
Via `Auditor::log` (explicit domain events, like auth and exports):
- `assignment.blocked`: tenant, initiator, employee, course or path, timestamp,
  `leave_start`, `leave_end`, `policy_enabled`, `block_reason = employee_on_leave`,
  `leave_data_stale`.
- `assignment.allowed_on_leave`: same shape, written when policy allows an assignment
  to an employee on leave. The enrolment `evidence` also gets
  `assigned_during_leave: {start, end}`.
- One `assignment.bulk_completed` row per bulk operation with the aggregate counts.
- Blocked rows are written per employee, which is what compliance needs. The audit
  chain takes a per-tenant lock per write, so this is measured on a 500-blocked
  fixture. If it is too slow, I batch them under one lock acquisition.

### 8. Settings UI
- Filament **"Assignment policy"** page with the toggle. It carries the label and help
  text from the issue, and is gated by a new permission (`settings.assignment_policy`)
  granted to the tenant-admin role. It is added to the seeder and rolled out with
  `permissions:sync`.
- Changing the setting writes an audit event.

## Work breakdown
1. `HrisLeaveSource`, `LeaveData`, mock leave generation, ExampleHR unsupported, and
   the `HrisManager` accessor. Tests: port contract and mock determinism.
2. `tenants.timezone` and `employee_leaves` migrations, models and factories, and
   `SyncLeave` with the command, mass-wipe guard and richer `SyncRun` reporting. Tests:
   upsert, cancel-removal, guard, unsupported adapter, tenant isolation.
2b. Slot dispatcher (`hris:dispatch-leave-syncs`) and scheduler registration. Tests:
   per-timezone 06:00/18:00 slots, idempotency, catch-up after a missed tick, DST day,
   manual runs not consuming a scheduled slot, failure notification.
3. `Tenant` setting accessor, `EmployeeAvailability` and `AssignmentEligibility`
   (date maths, flag precedence, staleness, tenant-local "today"). Unit-style tests,
   including boundary dates (start day, end day, day before and after).
4. Result types and enums, then refactor `AssignCourse` to the gate and the new return
   type, and update callers and existing tests.
5. `AssignLearningPath` gate (check before membership), then the bulk runners and the
   org-wide actions, with the path fan-out extracted from the Filament resource.
6. Notifications (single and bulk summary) and audit events.
7. Filament: settings page and permission, "Sync leave now", `EmployeeResource` bulk
   actions, and result rendering in all UIs.
8. Docs: README status and CHANGELOG, a note in DEPLOY.md on the new scheduled job,
   and `docs/sprint-13-report.md`.

## Tests (map to the issue's acceptance list)
Pest, in `tests/Feature/Leave/` and updates to the existing assignment tests.
- Course assignment, on leave: policy off → blocked; policy on → assigned with audit
  evidence; not on leave → unchanged.
- Bulk with mixed states: exact counts, blocked details with leave dates, and a batch
  that does not abort.
- Org-wide and department course assignment. Individual, bulk and org/department path
  assignment.
- Path blocked → no `PathEnrollment` and zero course enrolments.
- Already enrolled (including an on-leave employee who is already enrolled) → Skipped
  — already assigned. A terminal enrolment → skipped, not blocked.
- Request approval for an on-leave employee → blocked. Learner request and path
  self-enrol → unaffected.
- No `CourseAssignedNotification` or `PathAssignedNotification` for a blocked
  employee. The admin gets a single notification, and a bulk run yields one summary.
- Audit rows for blocked and allowed-on-leave with machine-readable reason.
- Tenant isolation: tenant A's setting and leave rows never affect tenant B.
- Stale (>13h) or missing leave data → fail open with `leave_data_stale` in the audit
  row. The assignment path never calls the HRIS port (asserted with a throwing fake).
- Manual sync: cooldown, no start while one is running, trigger and user recorded.
- Setting default is off. Settings page permission gating.

## Definition of done
- All acceptance criteria in issue #1 are checked off, each covered by a test.
- Every existing assignment flow goes through the gate. A test fails if a new caller
  writes an enrolment around `AssignCourse` (guard on direct `Enrollment::create` in
  `app/`).
- Full suite, Pint and PHPStan (L6) green on PHP 8.3. CI green on the PR.
- `docs/sprint-13-report.md` written. PR to `main` with `Closes #1`.

## Out of scope (per the issue's non-goals)
Rescheduling or auto-assigning after return from leave, editing leave records,
extending due dates for leave, and replacing HRIS leave management.
