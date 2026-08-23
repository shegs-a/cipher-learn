# Sprint 8 Plan — Notifications & Engagement

> Status: draft for review. First of the four sprints that finish the standard LMS
> (owner: "all the above" — Notifications → Learning paths → Responsive → Deploy).
> Right now the assignment loop is **silent**: a learner is never told they were
> assigned a course, nobody is reminded about overdue training, and no "certificate
> is ready" ever goes out. This sprint gives the loop a voice.

## Objective
When something happens to a learner's training, tell the right person — by **email**
and **in-app** — so the assign → learn → complete loop actually reaches people.

## Events (v1)
Fired from the domain actions that already own each transition, so a notification
can never disagree with the record:
1. **Course assigned** → the learner: what, why (the `rationale`), and the due date.
   From `AssignCourse` — **only on a real transition** (a new/`assigned` enrolment,
   or `requested → assigned`), never on an idempotent re-run.
2. **Access requested** → the approver(s): "{learner} requested {course}." From the
   learner's request flow.
3. **Request approved / rejected** → the learner.
4. **Course completed** → the learner: "You've completed {course} — your certificate
   is ready," linking the certificate. From `CompleteCourse`.
5. **Overdue reminder** → the learner: an open enrolment past its due date. A
   scheduled command, **deduped** so it nudges, not spams.

## Substrate
- **`notifications` table** — a migration (Laravel's `database` channel) with a
  **ULID-compatible `notifiable_id`** (`char(26)`, to match `users`); the default
  Laravel migration assumes a different key type, so we write our own.
- **Laravel Notifications**, `mail` + `database` channels, **queued**
  (`ShouldQueue`) so sending never blocks the request. Works on `sync` locally and
  `redis` in prod; the queue worker is wired in the deploy sprint.
- **In-app surfacing:** enable Filament's **database notifications** (the bell) for
  admin/manager users; add a lightweight **notifications list + unread indicator**
  to the learner portal.
- Mail stays `log` in dev (renderable in tests); real SMTP/SES is deploy-sprint config.

## Scheduling
- `notifications:overdue` console command — finds overdue open enrolments and
  notifies, **deduped** (a per-enrolment `last_reminded_at`, or a sent-log) so a
  learner isn't emailed every run. Runnable by hand now; wired to the scheduler in
  the deploy sprint (alongside `outbox:work` and `hris:sync-employees`).

## Tenant-scoping & safety
- Notifications target a specific `User`; the payload captures the data
  (course title, rationale, due date, cert serial) at send time, tenant-scoped.
- Idempotency: assignment/approval notifications fire only on the actual state
  change — the bulk/org-wide fan-out and re-runs must not re-notify.

## Tests (Pest — MySQL 8 in CI)
- `Notification::fake()`: each event notifies the right user with the right data;
  an **idempotent re-assign sends nothing**; org-wide fan-out notifies each new
  learner once.
- The overdue command notifies overdue learners once and respects the dedupe.
- Mail renders (subject/body has course + reason + due); database channel writes a
  row; both tenant-scoped.
- Acid test unaffected.

## Suggested build order
1. `notifications` migration + enable in-app (Filament bell + portal indicator).
2. Assignment + approval notifications (wired into the actions, idempotency-guarded)
   + tests.
3. Completion/certificate notification + tests.
4. `notifications:overdue` command + dedupe + tests.
5. Portal notifications list; browser walkthrough; close (report, PR).

## Rough size
~3–4 focused sessions. New deps: **none** (Laravel notifications are built-in).
Risk is in the idempotency guards (don't spam on re-runs) and the ULID notifiable
key — both covered by tests.

## Open decisions (recommendation in each)
- **(a) Channels** — mail **+** in-app(database) vs mail only → *both* (in-app is
  cheap once the table exists and gives managers the Filament bell).
- **(b) Event set** — the five above → *ship all five; they're the standard-LMS
  minimum for the loop to feel complete.*
- **(c) Notify managers too?** — beyond approver-on-request → *approver gets the
  request; learner gets assigned/overdue/completed. A manager overdue **digest** is
  deferred (nice-to-have, avoids noise in v1).*
- **(d) Overdue cadence** — remind at due date then weekly, deduped → *weekly after
  due, one notification per enrolment per week.* Tune later.

## Explicit non-goals (deferred)
Real SMTP/SES + queue worker + scheduler wiring → **Deploy sprint** · in-app chat /
messaging → later (separate from transactional notifications) · SMS/push → later ·
per-user notification preferences/opt-out → later (a fast-follow if wanted) ·
manager overdue digest → later.
