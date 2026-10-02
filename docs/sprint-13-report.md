# Sprint 13 Report — Leave-aware assignment (V2 opener)

Implements GitHub issue #1. Plan: [sprint-13-plan.md](sprint-13-plan.md).

## What shipped

**Policy.** `tenants.settings.allow_assignment_to_employees_on_leave`, default **off**,
edited on a new **Settings › Assignment policy** page (`settings.assignment_policy`,
Tenant Admin only; every change audited).

**Enforced in the domain layer.** `AssignCourse` and `AssignLearningPath` call the
shared `LeaveGate` / `AssignmentEligibility` before writing anything, so every flow
inherits it: individual, bulk selection, department, org-wide (course and path),
manager assignment from the Team page, and approving a learner's request. A tripwire
test fails if any other class writes an enrolment or path membership directly.

- Blocked ⇒ no enrolment, **no path membership and no per-course enrolments** (a path
  is all-or-nothing: the check runs once, up front), no employee notification.
- A learner's own *request* or *self-enrolling* in a path is their choice, not an admin
  assignment, and is not gated. Approving a request is an assignment and is.
- A re-run over something the employee already holds reports **Skipped — already
  assigned**, never Blocked, even if they have since gone on leave.

**Leave data, via the HRIS port.** `HrisLeaveSource` + `LeaveData`; the mock adapter
generates deterministic leave, ExampleHR reports leave as unsupported. A sync mirrors
approved leave into `employee_leaves` (idempotent upsert on the HRIS leave id,
cancelled leave removed, wipe guard against an empty feed).

**Assignment never calls the HRIS.** Eligibility reads only the local table (asserted
with a failing fake adapter). "Today" is the tenant's own calendar day. A leave verdict
the HRIS sent *today* wins over date maths; an older one is ignored.

**Schedule per tenant timezone.** `tenants.timezone` (required IANA name, validated on
save). The scheduler ticks every 10 minutes; `hris:dispatch-leave-syncs` runs each
tenant's **06:00 / 18:00** slot once, in that tenant's local time, catches a missed
tick, runs only the latest due slot after an outage, doesn't hammer a failed slot, and
handles daylight-saving days. Manual runs never consume a slot.

**Sync reporting.** `sync_runs` records trigger (scheduled/manual), who clicked, the
slot, counts (fetched/created/updated/removed/unmatched), duration and error, shown and
filterable in Sync history. **Failures only** email admins (`hris.sync`). The dashboard
shows leave-data freshness (amber when stale). **Sync leave now** is on the Employees and
Sync history pages, with a 5-minute cooldown and no start while a sync is running.

**Fail open.** Data older than 13 hours (or never synced, for a tenant whose HRIS has
leave) never blocks assignment; it is flagged `leave_data_stale` in the audit row, in the
result, and in the admin toast. A tenant whose HRIS has no leave feed is not "stale".

**Structured results.** `attempt()` returns `AssignmentItemResult`; bulk runners return
`AssignmentResult` (total / assigned / blocked / skipped split into already-assigned and
other, plus blocked and skipped employee lists with leave dates and a machine-readable
reason `employee_on_leave`). The admin sees it in the toast; blocked names are persisted.

**Notifications.** One `AssignmentBlockedNotification` for a single assignment; one
`BulkAssignmentBlockedNotification` per bulk operation (never one per employee). Existing
learner notifications are unchanged.

**Audit.** `assignment.blocked` (per blocked employee), `assignment.allowed_on_leave`
(once per assignment, once per path), `assignment.bulk_completed` (counts + full blocked
list), `settings.assignment_policy_changed`. Allowed-on-leave enrolments also carry
`evidence.assigned_during_leave`.

**New UI.** Bulk **Assign course / Assign learning path** on the Employees table.

## Decisions worth knowing

- `handle()` still returns the model so existing callers and all 243 original tests
  were unchanged; it **throws `AssignmentBlockedException`** when blocked, so nothing
  can silently carry on. New/UI code uses `attempt()`. This replaced the plan's
  "breaking change" — less churn, same guarantee.
- `AssignCourseOrgWide` returns an `AssignmentResult` instead of an int; two existing
  assertions were updated to `->assigned()`.
- A failed slot is recorded and not retried every tick; the next slot or the manual
  button covers it.
- Path fan-out used to live in a private Filament method; it is now a domain action.

## Not done / follow-ups

- No UI yet to edit a tenant's timezone after creation (there is no tenant admin screen
  or `tenant:provision` command yet — V2 backlog). It is set by the seeder/factory.
- The Employees table has no "on leave" column/filter.
- Pre-existing, untouched: re-assigning a course to someone *in progress* resets the
  enrolment status to `assigned` (`AssignCourse` writes the requested status over an open
  enrolment). Worth a separate issue — org-wide re-runs make it more visible.
- The audit chain takes a per-tenant lock per row, so a bulk run blocking thousands of
  employees writes thousands of rows serially; not measured at scale.

## Verification

Pest, Pint and PHPStan (level 6) green locally on PHP 8.3 (SQLite). See the PR for CI
on MySQL 8 / Redis.
