# Sprint 5 Report — Course Consumption + Write-back

**Status:** feature-complete · **162 tests** (was 129 at S4 close) · Pint + PHPStan
(L6) clean on PHP 8.3 · verified end-to-end on the **live Docker stack** (MySQL 8
/ Redis / MinIO), not just sqlite.

The portal used to only *list* assigned courses. Now a learner takes one all the
way through and the completion is written back to the HR record — the product
loop closes: **lessons → quiz → certificate → write-back.** No new tables; the
Sprint 1 schema (`lesson_progress`, `quiz_attempts`, `certificates`,
`outbox_events`) was ready.

## Delivered

**Course player** (`/portal/courses/{enrollment}`) — the learner works their own
course's lesson outline, opening and marking each complete. First completion flips
the enrolment `assigned → in_progress`; progress is real (lesson-based) across the
dashboard tiles, cards and Start/Continue button. `App\Actions\Learning\CompleteLesson`
is the one idempotent `lesson_progress` write.

**Assessment** (`/portal/courses/{enrollment}/assessment`, gated behind all
lessons complete) — `App\Actions\Learning\SubmitQuizAttempt` grades **server-side**
against the `$hidden` `correct_keys` (never serialised to the client), stores only
the learner's answers + score/pass, and enforces the attempt ceiling server-side.
Pass → the course completes via `App\Actions\Learning\CompleteCourse` (the single
completion transition); the last attempt exhausted without a pass → `failed`
(recovery is a fresh assignment).

**Certificate** — completion mints one via `App\Actions\Learning\IssueCertificate`
(idempotent, one per enrolment; readable globally-unique serial `SL-XXXX-XXXX-XXXX`;
`expires_at` from the course's recert months). A **Certificates** portal page (now
in the sidebar) lists the learner's own; each links to a printable certificate
(own-only) and a **public `/verify/{serial}`** page — no login, no tenant: a guest
binds no tenant, so the global-serial lookup resolves across tenants and shows a
genuine / recert-due / not-found result exposing only holder, course, org, dates.

**Write-back — the loop closes (transactional outbox).** On completion,
`App\Actions\Hris\EnqueueTrainingCompletion` stages a `training.completion`
outbox event **in the same transaction** — the learner never waits on a flaky HR
API. `App\Actions\Hris\ProcessOutbox` (the `outbox:work` command) drains due
events per tenant through the tenant's HRIS adapter:
- ExampleHR (no write-back endpoint) → **`unsupported`**: terminal, never
  retried, never counted as failure — the loop is built and provable, parked
  until an endpoint exists;
- the mock → **`processed`**;
- a transient `HrisConnectionException` → back-off retry, then **`failed`** past
  the cap; a non-HRIS employee (no external id) → `unsupported`.
A read-only Filament **Write-back log** (People group, gated `enrollments.view`)
is the operator's audit trail.

**Manager landing (Sprint 4 carry-over, settled).** A plain line Manager now lands
on `/portal` (where "My Team" lives) while keeping panel access via the switcher
(`User::landsOnAdminPanel` + `PANEL_LANDING_PERMISSIONS`).

**Seed** — course lessons now ship a readable text-first body (were titles only).

## Verified end-to-end (live browser + MySQL)
Learner took *Consultative Selling*: worked all three lessons (pill flipped to IN
PROGRESS, progress to 100%), passed the assessment **100%** → enrolment `completed`
with **no correct-keys in the stored attempt** → certificate issued → printable
certificate + public verify (genuine **and** not-found states) → `outbox:work`
drained the `training.completion` event to **`processed`**, visible in the
Write-back log. Every state confirmed in MySQL.

## Safety / isolation
- **Server-side grading only** — `correct_keys` is `$hidden`; a test asserts the
  stored attempt and a serialised question never contain it.
- **Own-enrolment scoping** — the player, assessment and printable certificate
  404 another employee's; still-`requested` enrolments 404.
- **Tenant isolation** holds on all four new tables (BelongsToTenant); the only
  cross-tenant surface is public verification, by design, exposing nothing beyond
  what a certificate attests.
- **Acid test intact** — Content Administrator still gains no learner/assign powers.

## A prod bug this surfaced
Building the real image (not sqlite) exposed a broken production Dockerfile: the
`composer` builder stage lacked PHP `intl`, which `filament/support v3.3.54` now
hard-requires. Fixed (`--ignore-platform-req=ext-intl` in the builder; the runtime
stage installs intl), verified by a clean `docker compose up --build`.

## Run it
```bash
# Tests / static analysis (PHP 8.3)
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/pest
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/pint --test
/opt/homebrew/opt/php@8.3/bin/php -d memory_limit=1G vendor/bin/phpstan analyse

# Full stack
docker compose up -d --build
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan outbox:work   # drain training-completion write-backs
```

## Not built (deferred, by design)
- **Automated performance-gap → auto-assign rules engine** → Sprint 6 · **admin
  overview dashboard** → Sprint 6 · **AI recommender** → Sprint 7 · **notifications
  & chat** (incl. "your certificate is ready") → Sprint 8.
- **PDF certificates** — HTML + browser "print to PDF" ships now; a PDF library is
  a later polish (owner-confirmed at kickoff).
- **Scheduling `outbox:work`** — the command exists; wiring it into the scheduler
  is a Sprint 8 (deploy) concern.
- **A live ExampleHR write-back endpoint** — doesn't exist; we park it
  `unsupported`, which is the honest end state.
