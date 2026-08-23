# Sprint 9 Plan — Learning Paths

> Status: draft for review. Second of the V1-finishing sprints (Notifications ✓ →
> **Learning paths** → Mobile/responsive → Deploy). The `LearningPath` model and
> the ordered `learning_path_course` pivot have existed since Sprint 1 but are
> completely unsurfaced — no UI, no assignment, no learner view. This sprint makes
> them real.

## Objective
Let an org bundle courses into an **ordered curriculum** (a path), **assign the
whole path** in one action, and see **path-level progress** — without reinventing
the learning journey, because a path is just a named, ordered set of courses that
each flow through the existing lessons → quiz → certificate → write-back loop.

## What's already in place
- **`LearningPath`** (name, description) + **`learning_path_course`** pivot with a
  `position` (composite PK, one row per course per path). `LearningPath::courses()`
  is ordered by position. Factory exists.
- **Permissions:** `learning_paths.view` / `learning_paths.manage` — held by
  Content Administrator, L&D Manager, Tenant Admin (not a plain Manager/Learner).
- Everything a course assignment needs: `AssignCourse` (idempotent, carries a
  rationale), org-wide fan-out, the whole learner journey, notifications.

## Design — a path is a grouping, assignment fans out
The load-bearing decision: **assigning a path fans out to a per-course enrolment**
(via `AssignCourse`), rather than inventing a first-class "path enrolment". This
reuses *everything* — lessons, quiz, certificate, write-back, the assigned/overdue/
completed notifications — for free. The path linkage is recorded in each
enrolment's `evidence` (`{learning_path_id, learning_path_name}`) so progress can
be grouped back together. (Alternative — a dedicated `path_enrollments` table —
would duplicate the journey and the status machine; not recommended for V1.)

- **`App\Actions\Learning\AssignLearningPath`** — given a path + a set of employees
  + a shared rationale/due/source, assigns each course in the path through
  `AssignCourse` (idempotent, evidence-linked). One entry point, reused by the
  org-wide and individual paths.
- **Path progress** for an employee = completed course-enrolments in the path ÷
  courses in the path (a small helper, mirroring `Enrollment::completionPercent()`).

## Surfaces
**Admin authoring (Filament, Catalogue group, gated `learning_paths.*`)**
- **`LearningPathResource`** — create/edit a path (name, description) and **attach +
  reorder courses** (a reorderable relation manager over the pivot's `position`).
  Mirrors `CourseResource`'s `AuthorizesViaPermissions` gating.
- **"Assign path to employees"** action (everyone / a department, shared reason,
  due) — gated `enrollments.assign_org`, mirroring the Course org-wide assign, but
  fanning out through `AssignLearningPath`.

**Learner (portal)**
- A **Learning paths** section/page listing the paths the learner is on, each with
  its ordered courses and a **path progress** bar. Individual courses still appear
  on My Learning (they're real enrolments); the path view groups them and shows the
  curriculum shape.

## Tests (Pest — MySQL 8 in CI)
- `AssignLearningPath` fans out one enrolment per course, each carrying the path
  linkage in `evidence`; **idempotent** (re-assign adds nothing); tenant-scoped;
  respects `AssignCourse`'s terminal-record guard.
- Path progress = completed ÷ total courses for the employee.
- `LearningPathResource` gated (Content Admin can author paths; a Learner/Manager
  cannot); the reorder persists `position`.
- Learner path view shows the path's courses + progress, own-employee scoped.
- Acid test intact.

## Suggested build order
1. `AssignLearningPath` action + path-progress helper + tests (the core).
2. `LearningPathResource` (authoring + reorderable courses) + the org-wide assign
   action + gating tests.
3. Learner Learning-paths portal section + progress + tests.
4. Seed a demo path; browser walkthrough; close (report, PR).

## Rough size
~3–4 focused sessions. Reuses the assignment + journey machinery, so the risk is in
the fan-out idempotency and the reorder UI — both covered by tests. New deps: none.

## Decisions — CONFIRMED at kickoff (owner, 2026-08-02)
- **Fan-out** enrolment (per-course records kept). ✓ (a)
- **Direct self-enrol** into a path — a learner browses paths and enrols; the path's
  courses are assigned immediately. (Was a non-goal; now in scope.)
- **Path certificates DEFERRED** to a later sprint. Course certificates behave
  **exactly as today** — a course taken inside a path still issues its own course
  certificate; there is no path-level certificate yet. So **no certificates schema
  change** and **no completion/issuance rework** this sprint.
- Non-sequential (display order only). ✓ (d)
- **`path_enrollments` linkage** — a lightweight row per (employee, path) set on
  assign/self-enrol, marking "this learner is on this path". It powers the learner
  paths list + progress and future-proofs the path-cert sprint. **Not** a status
  machine and **not** where a certificate attaches (that's the later sprint).

Original open-decisions write-up retained below for the record.

## Open decisions (recommendation in each)
- **(a) Assignment model** — fan-out to per-course enrolments (evidence-linked) vs a
  first-class path enrolment → *fan-out* (reuses the entire journey; a path is a
  grouping, not a new status machine).
- **(b) Learner surface** — a dedicated Learning-paths page/section vs only grouping
  on My Learning → *a light Learning-paths section with progress*; courses still
  show individually on My Learning.
- **(c) Assign scope for V1** — org-wide (HR/L&D) **+** individual, or org-wide only
  → *org-wide now (mirrors the Course action); individual/manager assign is a small
  fast-follow.*
- **(d) Sequential/locked progression** (must finish course 1 before 2) → *no — V1
  paths are an unordered-completion bundle with a display order; gated progression
  is a later enhancement.*

## Explicit non-goals (deferred)
Sequential/locked path progression → later · a **path-level certificate** (one cert
for finishing the whole path) → later · path-level recertification → later ·
per-manager individual path assignment UI → fast-follow if wanted · learner
self-enrol onto a path → later.
