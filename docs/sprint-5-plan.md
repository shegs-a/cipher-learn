# Sprint 5 Plan — Course Consumption + Write-back

> Status: draft for review. Sprints 0–4 merged to `main` (assignment loop shipped
> in PR #7). This sprint makes the portal's *listed* courses **actually takeable**
> and closes the product loop by writing completion back to the HR record.

## Objective
A learner takes an assigned course end-to-end, and the completion is recorded
back against the performance record:

**work through lessons → sit the quiz → pass/fail → certificate → write-back.**

Nothing new in the schema — Sprint 1 already shipped the four tables this needs
(`lesson_progress`, `quiz_attempts`, `certificates`, `outbox_events`), all empty
and waiting.

## What's already in place (verified against current code)
- **Content exists.** `DatabaseSeeder` seeds five real courses, each with ordered
  lessons and a quiz of graded questions (`correct_keys` populated). Lesson
  `content` is currently null (titles only) — the plan enriches a couple so
  "reading a lesson" isn't an empty page.
- **Grading is safe by construction.** `Question.correct_keys` is `$hidden` and
  cast away from arrays — it never serialises to the client. Grading is
  server-side only. `Quiz::effectivePassMark()` falls back to `Course.pass_mark`
  (default 70); `Quiz.max_attempts` falls back to `Course.max_attempts`.
- **Status flow is modelled.** `EnrollmentStatus` already has `InProgress`,
  `Completed`, `Failed`, `Waived`. This sprint drives the transitions
  `assigned → in_progress → completed | failed`.
- **The write-back port is whole.** `HrisWriteback::pushTrainingCompletion()` +
  `supportsWriteback()`, the `TrainingCompletionData` DTO, and both adapters
  exist. `MockHrisAdapter` succeeds; `ExampleHrAdapter` throws
  `HrisUnsupportedOperation` (permanent — no such endpoint). `outbox_events` has a
  dedicated **`unsupported`** terminal status precisely for this.
- **Certificates carry a public `serial` (globally unique) + a DATETIME
  `expires_at`** — the migration comments already anticipate login-free
  verification and long-dated recert.
- Portal is Livewire (`app/Livewire/Portal/*`): `Dashboard` (My Learning),
  `Catalogue`, `Team`. Design source: `~/Downloads/CipherLearn Learner App.html`
  (desktop screens 2–6). Sidebar already lists **My Learning / Progress / My team
  / Certificates**.

## The learning journey (no new tables)

### 1. Course player — lessons → progress
- **Course overview** (`/portal/courses/{enrollment}`) — the "why you were
  assigned this" rationale up top, lesson list with per-lesson status, data-cost
  (`file_size_bytes`) and `estimated_minutes` totals, and a quiz gate.
- **Lesson view** — render `content`; **Mark complete** writes a `lesson_progress`
  row (`unique(enrollment_id, lesson_id)`, idempotent). Opening the first lesson
  flips the enrolment `assigned → in_progress`.
- Course progress = completed lessons / total. The quiz unlocks when all lessons
  are complete (recommended gate — see open decision (d)).

### 2. Quiz — sit, grade server-side, pass/fail
- `App\Actions\Learning\StartQuizAttempt` and `SubmitQuizAttempt` — the **only**
  places a `quiz_attempts` row is written. Submission grades against
  `correct_keys` **on the server**, stores the learner's `answers` (never the
  keys) + `score` + `passed`, respecting `points` and question `type`
  (single/multiple/boolean).
- **Pass** (`score >= effectivePassMark`) → enrolment `completed`, issue a
  certificate, enqueue write-back (below).
- **Fail** → attempt recorded; the learner may retry until `max_attempts` is hit.
  Exhausting attempts without a pass → enrolment `failed` (see open decision (e)).
- Attempts are **idempotent per submission** and attempt-count enforced
  server-side (never trust a hidden field).

### 3. Certificate — issued on pass
- `App\Actions\Learning\IssueCertificate` — one certificate per enrolment
  (`unique(enrollment_id)`), a collision-resistant public `serial`, `issued_at`,
  and `expires_at` derived from the course's recert months when set (else null).
- **Certificates** portal page — the learner's earned certificates (design
  screen). A single certificate renders as a printable HTML page (see open
  decision (b)).
- **Public verification** (`/verify/{serial}`, no auth) — confirms a serial is
  genuine and shows course / holder / issued / expiry. The schema was built for
  this; recommend shipping the minimal version now (open decision (c)).

## Write-back — the product loop closes (transactional outbox)
On completion we must record the training against the HR record **without**
coupling the learner's request to a flaky external call. Use the **outbox**:

1. In the same DB transaction that marks the enrolment `completed` + issues the
   certificate, insert an `outbox_events` row: `type = training.completion`,
   `payload` = the `TrainingCompletionData` fields (external id, course title,
   completed_at, score, certificate serial). Status `pending`.
2. `App\Actions\Hris\ProcessOutbox` (invoked by an `outbox:work` console command)
   drains due `pending` events per tenant through `HrisWriteback`:
   - adapter **`supportsWriteback()` false** → mark **`unsupported`** immediately,
     never retried, never counted as failure (ExampleHR today);
   - `pushTrainingCompletion` succeeds → `processed`;
   - `HrisConnectionException` (transient) → `attempts++`, back-off via
     `available_at`; after a cap → `failed`;
   - `HrisUnsupportedOperation` thrown despite the guard → `unsupported`.
3. Idempotent + tenant-scoped; safe to run repeatedly. Filament gets a read-only
   **Outbox** view (audit) alongside the existing Sync Runs (open decision (f)).

This is the honest end state: for ExampleHR the loop is *built and provable* but
parks as `unsupported` until they ship an endpoint — no silent no-op.

## RBAC
No new permissions expected — a learner takes their **own** assigned courses (own
enrolment, tenant-scoped; enforced in the query/policy layer, mirroring Sprint 4's
own-reports scoping). Certificates are the learner's own; public verify is
unauthenticated by design. Flag if review wants an explicit `enrollments.take`.

## Tests (Pest — MySQL 8 in CI)
- Lesson complete writes one idempotent `lesson_progress`; first lesson flips
  `assigned → in_progress`.
- Quiz grading: correct keys never leave the server; score/pass computed from
  `correct_keys` + `points`; pass ≥ effective pass mark; multiple/boolean types.
- `max_attempts` enforced server-side; exhausting without a pass → `failed`.
- Pass → `completed` + exactly one certificate (unique serial, expiry from recert).
- Outbox: completion enqueues one `training.completion`; mock tenant → `processed`;
  ExampleHR tenant → **`unsupported`** (not retried, not failed); transient →
  retry then `failed`. Whole flow tenant-scoped.
- Public `/verify/{serial}` resolves a real serial, 404s a bogus one, leaks no PII
  beyond course/holder/dates.
- Acid test still holds (Content Admin unaffected; a learner can't take another
  tenant's / another employee's enrolment).

## Suggested build order
1. Course overview + lesson view + `lesson_progress` (+ `assigned → in_progress`).
2. `StartQuizAttempt` / `SubmitQuizAttempt` + server-side grading + attempts.
3. `IssueCertificate` on pass → `completed`; Certificates page; public verify.
4. Outbox: enqueue on completion + `ProcessOutbox` + `outbox:work` + Filament view.
5. Enrich a little lesson `content` in the seeder; browser walkthrough.
6. Close: raw acid-test/isolation, run commands, report, PR.

## Rough size
~5–7 focused sessions. The load-bearing third is (2)+(4): server-side grading and
the outbox are where correctness and the product thesis live. **New deps: none**
(HTML certificate, no PDF lib — see (b)).

## Open decisions — CONFIRMED at kickoff (owner, 2026-07-30)
- **Scope:** full journey **+** outbox write-back, both this sprint (a/f below).
- **(b) Certificate:** HTML page **+** public `/verify/{serial}` this sprint (c).
- **(e) Attempts exhausted:** enrolment `failed`, recover via fresh assignment.
- **(g) Manager landing:** land plain Managers on `/portal` (settles the S4 item).
- **(d) Quiz gate:** require all lessons complete first (recommended default kept).

Original write-up with trade-offs retained below for the record.

## Open decisions (recommendation in each — confirm at kickoff)
- **(a) Write-back trigger** — dispatch a queued job on completion vs an
  outbox row drained by a scheduled `outbox:work`. → *Outbox row + command
  (the migration already commits to the outbox pattern; decouples completion from
  the external call, and it's the shape Sprint 6 automation reuses).*
- **(b) Certificate format** — printable HTML page vs generated PDF. → *HTML now
  (no new dep, matches the text-first / metered-data ethos; browser "print to PDF"
  covers download). PDF a later polish.*
- **(c) Public verification** — ship `/verify/{serial}` this sprint vs defer. →
  *Ship minimal now — the schema was explicitly built for it and it's the
  credible-credential payoff of the whole journey.*
- **(d) Quiz gate** — require all lessons complete before the quiz vs let learners
  jump straight to it. → *Require lessons first (the assignment is the learning,
  not just the test); revisit if too rigid.*
- **(e) Attempts exhausted** — enrolment `failed` and recovery is a fresh
  assignment (new cycle), vs auto-reset attempts. → *`failed` + re-assign
  (auditable; the cycle-unique key already supports re-enrol without clobbering
  history). No silent reset.*
- **(f) Outbox surfacing** — read-only Filament Outbox resource this sprint vs
  console-only. → *Add the read-only resource (operators need to see the parked
  `unsupported` events; cheap, mirrors Sync Runs).*

## Carried-over item (from Sprint 4)
**Manager landing UX** — a plain Manager routes to `/admin` on login while *My
Team* lives in the portal. Recommend settling it early this sprint (small: either
land managers on the portal, or expose a manager view in the panel). Flagged as
(g) for the kickoff.

## Explicit non-goals (deferred)
Automated performance-gap → auto-assign rules engine → **Sprint 6** · admin
overview dashboard → Sprint 6 · AI recommender → Sprint 7 · notifications & chat
(incl. "your certificate is ready") → Sprint 8 · a live ExampleHR write-back
endpoint (doesn't exist — we park it `unsupported`, by design).
