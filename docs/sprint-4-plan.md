# Sprint 4 Plan — Assignment & Discovery

> Status: draft for review. Sprint 3 (RBAC) assumed merged. Reshaped from the
> original "assignment engine": the **manual** assignment loop and the learner's
> view of it, first — the automated rules engine (now Sprint 6) just automates
> this same enrollment-with-a-rationale.

## Objective
A course assignment carries a **written reason**. Build that loop, manually,
across three roles, plus the learner's ability to see and request courses:

- **Manager** — assign a course to one of *their reports*, with a reason.
- **Admin / HR** — assign a course **org-wide** (all, or filtered), with a reason.
- **Learner** — see my assigned courses, browse the catalogue, and request access
  to a course I wasn't assigned (**request → approval**).

Everything reads/writes `enrollments`; no course consumption, no automated rules,
no notifications/chat this sprint.

### Confirmed at kickoff
- Sprint 4 is **assignment + discovery only** — actually taking a course
  (lessons → quiz → certificate) is Sprint 5.
- Requesting a non-assigned course is **request → approval** (manager/admin
  approves), not instant self-enrol.
- **Notifications and chat are deferred** (engagement slice / Sprint 8).

## What's already in place
- `enrollments`: `source` (manual/self/onboarding/rule/recommender), `method`,
  **`rationale`**, `evidence` (json), `due_at`, `assigned_by_user_id`, `status`,
  cycle-scoped unique `(tenant_id, employee_id, course_id, cycle)`. Everything an
  assignment needs already exists — no new tables.
- RBAC (Sprint 3): roles, permissions, tenant scoping, the learner portal shell
  and the "why you were assigned this" card already rendering `rationale`.
- `Employee::reports()` (manager → direct reports) and `Employee::manager()`.

## The one schema touch — request state
A learner's request is a **proposed** enrolment awaiting approval. Add
`EnrollmentStatus::Requested` ('requested'): a request creates
`enrollment(source=self, status=requested, evidence={note})`; approval flips it to
`assigned` and sets the approver's `rationale` + `due_at`; rejection → `cancelled`.
(`status` is a varchar, so this is an enum case, not a migration.)

*Alternative considered:* a dedicated `enrollment_requests` table — cleaner
separation but a whole extra resource. The status approach reuses the enrolment's
`rationale`/`evidence` and the cycle-unique guard; recommended for this sprint.

## RBAC additions
New permissions (added to `RolesAndPermissionsSeeder`):
- `enrollments.assign` — assign to an individual. **Manager** (scoped to own
  reports, enforced in the query/policy layer), L&D Manager, Tenant Admin.
- `enrollments.assign_org` — org-wide / bulk assignment. L&D Manager, Tenant
  Admin (HR) — **not** a plain Manager.

Content Administrator gets neither (still authoring-only — protects the acid
test). Learners need no permission to request their *own* access.

## The assignment service — `App\Actions\Assignment\AssignCourse`
One entry point, mirroring `ElevateEmployeeToRole`. Given an employee, a course,
a rationale, a source and an optional due date + assigner, it **upserts** the
enrolment on `(tenant, employee, course, cycle)` — idempotent, and it converts a
pending `requested` enrolment into an `assigned` one instead of colliding. Reused
by every path below (manager assign, org-wide assign, request approval).

## Surfaces

**Learner portal (web) — aligns with the design**
- **My Learning** — flesh out the existing dashboard (assigned courses + the
  "why" card, already there).
- **Catalogue** — a new portal page listing the tenant's *published* courses,
  with a "Request access" button on any the learner isn't already enrolled in.
- **Request access** — creates a `requested` enrolment with the learner's note;
  shows its pending state on My Learning.
- **My Team** (managers) — the manager's reports, each with their assigned
  courses/status, and an **"Assign course"** action (course + reason + due date),
  scoped to their reports. This is the screen matching the design's manager view.

**Admin panel (Filament) — operator bulk**
- **Org-wide assignment** — an action (on the Course resource, or a dedicated
  page) to assign a course to employees filtered by department/location/all, with
  a shared reason. Gated `enrollments.assign_org`.
- **Enrolments** — a read view of assignments (who has what, source, status, the
  reason, who assigned it), gated `enrollments.view`. Also where an admin
  approves/rejects learner requests.

**Request approval** — a manager approves requests from their reports; an admin
approves any. Approval runs `AssignCourse`; rejection cancels.

## Tests (Pest — MySQL 8 in CI)
- `AssignCourse`: creates an enrolment with rationale/source/assigner; idempotent;
  converts a `requested` enrolment to `assigned`.
- Manager can assign to a **direct report**, and is refused for a non-report
  (own-reports scoping).
- Admin org-wide assign enrols the filtered set with the shared reason.
- Learner request creates a `requested` enrolment; approval → `assigned` with the
  approver's rationale; rejection → `cancelled`.
- Catalogue shows published courses and hides ones the learner is already enrolled
  in; tenant-scoped throughout.
- Content Administrator cannot assign (acid test still holds).

## Explicit non-goals (deferred)
Taking courses — lessons/quizzes/certificates → **Sprint 5** · write-back to the
performance record → Sprint 5 · automated rules engine (performance-gap →
auto-assign) → **Sprint 6** · AI recommender → Sprint 7 · notifications & chat →
Sprint 8 (engagement).

## Suggested build order
1. `EnrollmentStatus::Requested` + new permissions in the seeder + role grants.
2. `AssignCourse` service + tests (the core).
3. Manager "own reports" scoping (policy/query helper) + tests.
4. Learner portal: Catalogue + Request access.
5. Manager: My Team page + assign action.
6. Admin: org-wide assignment + Enrolments view + request approval.
7. Sprint close: raw acid-test/isolation, run commands, PR.

## Rough size
~5–7 focused sessions; steps 1–3 are the load-bearing third (the service + scoping
is what everything else calls). New deps: **none**.

## Open decisions (recommendation in each)
- **(a)** Request state as a `Requested` enrolment status vs a separate
  `enrollment_requests` table → *status (lean; reuses rationale/evidence)*.
- **(b)** Org-wide assignment in Filament vs the learner portal → *Filament (it's
  an operator bulk action; managers/learners stay in the portal)*.
- **(c)** One `enrollments.assign` with role-based scope vs separate `assign` /
  `assign_org` → *two permissions; org-wide is a distinct, higher-blast-radius
  capability HR holds and a line manager does not*.
