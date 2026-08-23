# Sprint 4 Report — Assignment & Discovery

**Status:** feature-complete · PR [#7](https://github.com/shegs-a/cipher-learn/pull/7) ·
**129 tests** (was 106) · Pint + PHPStan (L6) clean · CI runs Pest on MySQL 8.

The heart of the product: a course assignment carries a **written reason**. Built
manually across three roles, plus the learner's view of it. (Reshaped from the
original "assignment engine" — the automated rules engine, now Sprint 6, just
automates this same enrolment-with-a-rationale later.) No new tables — the
Sprint 1 `enrollments` schema was ready.

## Delivered

**Core**
- `EnrollmentStatus::Requested` — a learner's pending request; approval flips it to
  `Assigned`, rejection to `Cancelled`. Enum case, **not a migration**.
- Permissions `enrollments.assign` (Manager → own reports, L&D, Tenant Admin) and
  `enrollments.assign_org` (L&D, Admin/HR only). Content Administrator gets
  neither (acid test extended).
- `App\Actions\Assignment\AssignCourse` — the one place an enrolment is
  created/updated. Idempotent upsert on `(tenant, employee, course, cycle)`;
  converts `requested` → `assigned` (that's request→approval); never clobbers a
  terminal record. `AssignCourseOrgWide` fans it out across a segment.
- Gate `assign-course-to` — a line manager may only assign to their direct
  reports; org assigners reach anyone.

**Manager (web portal)** — `/portal/team`: reports + their open courses, "Assign a
course" (course + reason + due). Refuses non-reports. "My team" sidebar link is
live for managers.

**Admin / HR (Filament)** — Course "Assign to employees" (everyone or a
department, shared reason, gated `enrollments.assign_org`); **Enrolments** resource
(read-only, gated `enrollments.view`, hidden from Content Admin) with Approve /
Reject on requests, each scoped by `assign-course-to`.

**Learner (web portal)** — `/portal/catalogue`: published courses; enrolled show
"On your list", the rest offer **Request access** (a note → pending request).
My Learning shows a "PENDING APPROVAL" pill for requests and links to the catalogue.

## Verified end-to-end (browser walkthrough)
Manager assigned Consultative Selling to a report with a reason → the learner saw
it under **"why you were assigned this"** with that exact reason → the learner
requested Debt Collections Fundamentals → an admin approved it into an assignment
(reason + approved-by recorded). Confirmed at every step.

## Decisions & open item
- Kickoff (owner-confirmed): assignment/discovery only (no course-taking); request
  → approval (not self-enrol); notifications & chat deferred.
- **Open UX item (flagged, not settled):** a plain **Manager** holds
  `employees.view`, so `canAccessPanel` routes them to `/admin` on login while
  *My Team* lives in the learner portal (reachable via the switcher / directly).
  Decide whether managers land on the portal, or *My Team* moves into the panel.

## Non-goals (deferred)
Taking courses — lessons/quizzes/certificates → **Sprint 5** · write-back to the
performance record → Sprint 5 · automated rules engine → **Sprint 6** · AI
recommender → Sprint 7 · notifications & chat → Sprint 8.
