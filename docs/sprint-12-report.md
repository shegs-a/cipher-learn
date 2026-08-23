# Sprint 12 Report — Deploy + demo (V1 close)

**Status:** feature-complete · **243 tests** (was 239 at S11 close; +4) · Pint +
PHPStan (L6) clean on PHP 8.3. **The last V1 sprint** — CipherLearn is now
production-deployable with the operational glue wired and a runbook.

## Objective
Make the app **production-deployable** in its current SaaS form and give it a
**runnable demo** — closing the operational gaps (scheduler, queue worker, release
automation, prod config, deliverable mail) and documenting the deploy.

## Scope (owner-confirmed at kickoff)
- **Production-ready package + runbook**, verified locally. **Real cloud
  provisioning stays the owner's step** (needs their accounts/secrets; the agent
  can't enter credentials).
- **Env-driven mail with a safe default** + **Mailpit** in the dev/demo stack so
  sent mail is visible.
- **Out of scope (V2, per earlier decisions):** single-tenant / on-prem mode + the
  IP-protection packaging; real SSO; the audit V2 hardening.

## What was already in place (reused, not rebuilt)
Multi-stage Dockerfile (vendor → assets → `app`), an entrypoint that generates
`APP_KEY` + runs `migrate --force`, `/healthz`+`/readyz`, the four operational
commands, and the demo seeder.

## Delivered
- **Scheduler wired** (`bootstrap/app.php withSchedule`) — the commands existed but
  were never scheduled: `outbox:work` (every minute), `hris:sync-employees --all`
  (02:00), `audit:verify --all` (03:00), `notifications:overdue` (07:00); each
  `withoutOverlapping()` + `onOneServer()`.
- **`permissions:sync {--tenant=}`** — re-runs the role/permission grants across
  **every tenant** (via `Tenancy::runFor`), so a release that adds a permission
  reaches all tenant admins, not just one. Idempotent. (Fixes the exact failure
  mode the owner hit after Sprint 10.)
- **Release automation** — the app entrypoint now runs `permissions:sync` after
  `migrate --force`, and caches config/routes/views in production. `APP_KEY` is only
  generated when none is provided, so a prod-supplied key is never rotated. A
  separate **worker entrypoint** lets the scheduler/queue containers wait for the DB
  **without** migrating (no first-boot race).
- **Prod stack** (`docker-compose.prod.yml`) — app + web + **scheduler** + **queue
  worker** + mysql/redis/minio; `APP_ENV=production`, `APP_DEBUG=false`; nginx and
  php-fpm share code via a named volume so paths match.
- **Mail** — env-driven; **Mailpit** added to the dev/demo stack (inbox at
  <http://localhost:8025>), dev mail points at it.
- **`.env.production.example`** (debug off, real DB/redis/S3/SMTP placeholders) and
  **`docs/DEPLOY.md`** runbook.

## Tests (+4 → 243)
- `ScheduleTest`: the four operational commands are registered on the scheduler.
- `SyncPermissionsCommandTest`: re-grants a permission missing across tenants;
  targets a single tenant; idempotent re-run.

## Verified locally (against the Docker stack)
- `permissions:sync` ran against live MySQL (synced the demo tenant).
- `schedule:list` shows all four jobs with correct cron times.
- A Redis-queued notification was processed by the **queue worker** and delivered to
  **Mailpit** (visible in the inbox) — the full queue → mail path end-to-end.

## Deploy caveat (documented in DEPLOY.md)
In the reference `docker-compose.prod.yml`, the `app-code` volume is seeded from the
image only while empty, so a new build needs the volume recreated to pick up new
code. In a real orchestrator (ECS/K8s) this caveat disappears — each task/pod runs
the image directly.

## V1 complete — what ships
HRIS employee sync · RBAC (spatie teams, per-tenant) · manual
assignment-with-a-reason + request→approval · course consumption (lessons, quizzes,
certificates) + HRIS write-back (transactional outbox) · learning paths · admin
overview dashboard · reports + CSV export · notifications (in-app + email) ·
tamper-evident audit trail · responsive learner portal · production packaging.

## Next — V2 backlog (in memory)
The differentiator: performance-gap **rules engine** + **AI recommender**, built as a
**hosted service** for IP protection. Then: single-tenant / on-prem mode +
`tenant:provision` + real SSO; audit V2 hardening (DB-level WORM, HMAC, allowlist
redaction); path-level certificates.
