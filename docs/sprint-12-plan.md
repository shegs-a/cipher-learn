# Sprint 12 Plan — Deploy + demo (V1 close)

**Goal:** make CipherLearn **production-deployable** in its current SaaS form and
give it a **runnable demo** — wiring the operational glue the app has been missing
(scheduler, queue worker, release automation, prod config, deliverable mail) and
documenting the deploy. The last V1 sprint.

## Scope (owner-confirmed at kickoff)
- **Production-ready package + runbook** — a prod compose stack + `DEPLOY.md`,
  verified locally. **Real cloud provisioning stays the owner's step** (it needs
  their accounts/secrets; entering credentials is out of bounds for the agent).
- **Env-driven mail with a safe default** — mail works via env (SMTP/SES/Mailgun)
  with no code change, defaults to `log`; a **Mailpit** service in the dev/demo
  stack so sent mail is visible. No real credentials handled by the agent.
- **Out of scope (deferred to V2, per earlier owner decisions):** single-tenant /
  on-prem mode + the IP-protection packaging (encoded artifact, entitlement); real
  SSO/IdP; the audit V2 hardening (DB-level WORM, HMAC, allowlist redaction).

## What already exists (don't rebuild)
- Multi-stage `Dockerfile` (vendor → assets(`npm run build`) → `app` php:8.3-fpm).
- `docker/php/entrypoint.sh`: waits for DB, generates `APP_KEY` if missing, runs
  `php artisan migrate --force`, then execs.
- Health endpoints `/healthz` + `/readyz`.
- The four operational commands: `hris:sync-employees`, `notifications:overdue`,
  `outbox:work`, `audit:verify`.
- Demo seeder (`DatabaseSeeder`) builds a full demo tenant + admin.

## The gaps to close
1. **Nothing is scheduled.** Wire the scheduler in `bootstrap/app.php`
   (`->withSchedule(...)`), `withoutOverlapping()` + `onOneServer()`:
   - `outbox:work` — every minute (drain the write-back queue).
   - `hris:sync-employees --all` — daily (early AM).
   - `notifications:overdue` — daily (morning).
   - `audit:verify --all` — daily (integrity check; non-zero exit is the alarm).
2. **No queue worker** in the stack, though notifications are queued on Redis. Add a
   dedicated worker service.
3. **`permissions:sync` command doesn't exist.** Build it (owner-requested): re-run
   the `RolesAndPermissionsSeeder` grants across **every tenant** (via
   `Tenancy::runFor`), so a release that adds a permission (like Sprint 10's
   `audit.view`) reaches all tenant admins — not just the demo tenant. Idempotent.
4. **Release automation.** Entrypoint (or a `release` step) runs `migrate --force`
   **and** `permissions:sync` on every deploy. Prod also caches config/route/view.
5. **Prod config.** `APP_ENV=production`, `APP_DEBUG=false` via a
   `.env.production.example`; generic error pages (no debug trace — the owner saw
   the debug page leak SQL + cookies during the Sprint 10 manual test).
6. **Mail.** Env-driven (already `MAIL_MAILER` env); add **Mailpit** to the dev/demo
   compose (SMTP 1025 / UI 8025) and point the demo at it; document real SMTP/SES.

## Work breakdown
1. **Scheduler** — `bootstrap/app.php withSchedule`, with the cadence above; a test
   asserting the four commands are registered (`Schedule` inspection).
2. **`permissions:sync`** — `App\Console\Commands\SyncPermissions` (`--tenant=` |
   all tenants). Test: a newly added permission lands on the right roles across
   multiple tenants; idempotent re-run is a no-op.
3. **Entrypoint / release** — add `permissions:sync` after `migrate --force`;
   prod-only `config:cache`/`route:cache`/`view:cache` (guarded so dev bind-mounts
   still hot-reload).
4. **Prod compose** — `docker-compose.prod.yml`: `app` (php-fpm), `web` (nginx),
   `scheduler` (`php artisan schedule:work`), `queue` (`php artisan queue:work
   redis --tries=3`), `mysql`, `redis`, `minio`; all reuse the built app image;
   `APP_ENV=production`, `APP_DEBUG=false`; healthchecks wired.
5. **Mailpit** — add to the dev/demo compose; demo env points mail at it.
6. **`.env.production.example`** — documented prod env (debug off, real mail/S3/DB
   placeholders, queue=redis, session=redis).
7. **`DEPLOY.md`** — build the image, configure env, first-boot (migrate +
   permissions:sync run automatically), run scheduler + worker, mail setup, health
   checks, and how to load the demo.
8. **Verify locally** — bring the stack up; confirm: a scheduled run fires,
   `permissions:sync` grants across tenants, a queued notification lands in
   **Mailpit**, `/healthz`+`/readyz` are green, and the demo loads end-to-end.

## Definition of done
- `docker compose -f docker-compose.prod.yml up` yields a healthy stack; first boot
  migrates + syncs permissions automatically; scheduler + worker run.
- A queued notification is delivered (visible in Mailpit); `permissions:sync` works
  across tenants with a test; the scheduler registers the four commands.
- `APP_DEBUG=false` in prod; `DEPLOY.md` written.
- Full suite + Pint + PHPStan (L6) green on PHP 8.3; `docs/sprint-12-report.md`; PR.

## After V1
V2 backlog (in memory): the differentiator (performance-gap rules engine + AI
recommender, built as a hosted service for IP protection), single-tenant/on-prem
mode + `tenant:provision` + real SSO, and the audit V2 hardening.
