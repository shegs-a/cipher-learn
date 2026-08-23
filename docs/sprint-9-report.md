# Sprint 9 Report — Learning Paths

**Status:** feature-complete · **212 tests** (was 202 at S8 close) · Pint + PHPStan
(L6) clean on PHP 8.3. Third of the four V1-finishing sprints (Notifications ✓ →
**Learning paths** → Mobile/responsive → Deploy).

## Objective
Surface the long-dormant `LearningPath` model: bundle courses into an **ordered
curriculum**, assign or self-enrol into the whole thing at once, and show
**path-level progress** — all by reusing the existing course journey rather than
reinventing it.

## Scope (owner-confirmed at kickoff)
- **Fan-out enrolment** — assigning/joining a path assigns each course as a normal
  enrolment, so every course keeps its own record, progress, quiz, **certificate**
  and HR write-back.
- **Direct self-enrol** — a learner joins a path and its courses land on My Learning
  immediately.
- **Path certificates: deferred** to a later sprint. Course certificates behave
  exactly as today; there is no path-level certificate yet, so **no certificates
  schema change** this sprint. (The design + implications are captured in the plan
  for when we pick it up.)
- Non-sequential (display order only).

## Delivered
- **`path_enrollments`** — a lightweight "employee is on this path" linkage row
  (not a status machine, not where a certificate attaches). Path **progress** is
  computed live from the real course enrolments, so it never drifts.
- **`App\Actions\Learning\AssignLearningPath`** — the one place a path is assigned
  (admin or self). Records the membership + fans out a per-course enrolment via
  `AssignCourse` (evidence-tagged with the path). Idempotent; per-course "assigned"
  notices are **suppressed** (new `AssignCourse` `notify` flag) in favour of one
  `PathAssignedNotification`.
- **Admin authoring** — `LearningPathResource` (Catalogue, gated `learning_paths.*`):
  name/description + a **reorderable Courses relation manager** (drag to set the
  pivot order), and an **"Assign to employees"** org-wide action (gated
  `enrollments.assign_org`).
- **Learner view** — `/portal/paths` ("Learning paths" in the sidebar): the paths
  you're on with a **progress bar + per-course status**, plus available paths with a
  direct **Enrol** button.
- **Seed** — a demo **"Commercial Onboarding"** path (Consultative Selling → Debt
  Collections → Financial Compliance & AML).

## Tests
- `AssignLearningPath`: fans out one enrolment per course (path-tagged); **one** path
  notification, not one per course; **idempotent** (re-assign adds nothing, no
  re-notify); progress = completed ÷ total; tenant-scoped.
- `LearningPathResource`: gated (L&D/Content Admin/Tenant Admin author; a Learner
  can't); the list renders.
- Learner paths view: available paths listed; **direct self-enrol** creates the
  membership + course enrolments (source `self`); own paths show with progress; a
  joined path drops off the "available" list.
- 212 total (was 202); Pint + PHPStan (L6) clean.

## A bug caught before CI
The `path_enrollments` unique index's auto-generated name exceeded **MySQL's
64-char limit** — invisible on sqlite (local tests passed), but it would fail on
MySQL/CI. Fixed with an explicit short index name and verified against the live
MySQL container.

## Run it
```bash
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/pest
docker compose up -d --build && docker compose exec app php artisan migrate --seed
# admin@cipherlearn.test / password → Catalogue › Learning paths
# a learner → portal › Learning paths
```

## Not built (deferred, by design)
- **Path-level certificates** (one certificate for finishing a whole path) → a
  dedicated follow-up sprint (needs a certificates schema change + issuance rework;
  design captured in `docs/sprint-9-plan.md`).
- Sequential/locked progression · path recertification · per-manager individual
  path assignment UI · learner self-enrol as request→approval → later.

## Next (finishing V1)
Sprint 10 **Mobile/responsive** → Sprint 11 **Deploy + demo** (wires the scheduler
+ real mail).
