# Sprint 8 Report — Notifications & Engagement

**Status:** feature-complete · **202 tests** (was 190 at S7 close) · Pint + PHPStan
(L6) clean on PHP 8.3 · verified on the live Docker stack (assign → notification →
bell). First of the four sprints finishing the standard LMS.

## Objective
The assignment loop was **silent** — a learner was never told they'd been assigned
a course, nobody was reminded about overdue training, and no "certificate is ready"
went out. This sprint gives the loop a voice, by **email and in-app**.

## Delivered
**Substrate**
- A ULID-compatible **`notifications` table** (Laravel's `database` channel;
  `notifiable_id char(26)` to match `users` — the framework stub assumes a
  different key type).
- **Filament in-app bell** (`databaseNotifications`, polled) for panel users, and a
  **learner-portal bell** (unread badge + dropdown, mark-as-read on open).

**Five queued notifications** (mail **+** database), each carrying **scalar data,
not Eloquent models** — the queue worker runs outside any tenant context, so a
serialized tenant-scoped model would fail to re-fetch:
1. **Course assigned** → the learner (what, why, due). Fired from `AssignCourse`,
   **transition-guarded** so an idempotent re-assign or org-wide fan-out never
   re-notifies, and an approved request (requested→assigned) counts as "assigned".
2. **Access requested** → the learner's manager (the approver).
3. **Request rejected** → the learner (approval isn't a separate notice — it becomes
   an assignment).
4. **Course completed** → the learner, linking the new certificate (from
   `CompleteCourse`).
5. **Overdue reminder** → the learner, via `notifications:overdue` — a cross-tenant
   sweep, **deduped** per enrolment (`reminded_at` + a `--days` cadence) so a daily
   scheduler doesn't re-email the same course.

A learner or manager **without a login is skipped gracefully** (HRIS employees
only get notified once they have an account).

## Verified end-to-end
On the live stack: assigning a course to the learner produced a notification, and
the portal bell dropdown showed it (New course assigned · Course completed) with
correct titles and timestamps. (The unread *badge* needs an asset rebuild to style
in the running container — its CSS is frozen from image-build time; correctness is
covered by tests.)

## Tests
- Each event notifies the right user with the right data; **an idempotent
  re-assign sends nothing**; a request notifies the manager (not the learner);
  approval notifies the learner; completion notifies the learner; **no login →
  nothing sent**.
- Overdue: reminds once + stamps `reminded_at`; **does not re-remind within the
  cadence**; ignores not-yet-due and completed enrolments.
- Portal bell shows unread notifications and marks them read.
- 202 total (was 190); Pint + PHPStan (L6) clean.

## Run it
```bash
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/pest
php artisan notifications:overdue          # send overdue reminders (deduped)
# mail is MAIL_MAILER=log in dev; notifications are queued (sync locally, redis in prod)
```

## Not built (deferred, by design)
- **Real SMTP/SES + the queue worker + scheduler wiring** (`notifications:overdue`,
  `outbox:work`, `hris:sync-employees`) → **Deploy sprint**.
- **Per-user notification preferences / opt-out** → fast-follow if wanted.
- **Manager overdue digest**, **in-app chat/messaging**, **SMS/push** → later.
- **Portal bell badge styling in the running container** — needs an image asset
  rebuild (deploy sprint); the feature and counts are test-verified.

## Next (finishing V1)
Sprint 9 **Learning paths** → Sprint 10 **Mobile/responsive** → Sprint 11
**Deploy + demo** (which also wires the scheduler + real mail).
