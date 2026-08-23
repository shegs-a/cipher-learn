# Deploying CipherLearn

The runbook for standing up CipherLearn in production. V1 ships as a **row-level
multi-tenant SaaS**: one image, one database, tenants as rows. (Single-tenant /
on-prem mode and the IP-protection packaging are V2 — see the sprint notes.)

## What the image is
A multi-stage `Dockerfile` produces one runtime image (`app` stage, PHP 8.3-FPM):
- `vendor` stage — `composer install --no-dev` (production dependencies).
- `assets` stage — `npm ci && npm run build` (compiled CSS/JS; **required**, or new
  Tailwind classes won't be styled).
- `app` stage — PHP-FPM + the code + vendor + built assets. Self-contained; no bind
  mount in production.

The same image runs four roles, differing only by command:
| Role | Command | Entrypoint |
|------|---------|-----------|
| web app | `php-fpm` | `entrypoint.sh` (runs the release steps) |
| scheduler | `php artisan schedule:work` | `worker-entrypoint.sh` (wait-for-db only) |
| queue worker | `php artisan queue:work redis --tries=3` | `worker-entrypoint.sh` |
| one-off | `php artisan <cmd>` | — |

## Release steps (automatic)
`docker/php/entrypoint.sh` runs on every boot of the **app** container, all
idempotent:
1. Wait for the database to accept connections.
2. `key:generate` **only if** no `APP_KEY` is provided (in prod you supply it — see
   below — so this never fires and never rotates your key).
3. `php artisan migrate --force`.
4. `php artisan permissions:sync` — rolls the permission vocabulary + role grants
   out to **every tenant**, so a release that adds a permission reaches all tenant
   admins, not just one. (Without this, a long-lived tenant silently misses new
   permissions — the failure mode we hit after the audit sprint.)
5. In production (`APP_ENV=production`): `config:cache`, `route:cache`, `view:cache`.

The scheduler and queue workers use `worker-entrypoint.sh`, which **only** waits for
the DB — they never migrate (the app container owns the release steps; having every
worker migrate would race on first boot).

## Configuration
1. `cp .env.production.example .env` and fill every `CHANGEME`. At minimum:
   - `APP_KEY` — generate once: `php artisan key:generate --show`, store it as a
     **secret**, set it here. Never let prod auto-generate (it would rotate on a
     fresh volume and invalidate sessions/encrypted data).
   - `APP_URL`, `SESSION_DOMAIN` — your HTTPS host.
   - `DB_*`, `REDIS_*` — your **managed** MySQL 8 / Redis (recommended over the
     bundled containers).
   - `AWS_*` — your S3-compatible bucket (S3 / OBS).
   - `MAIL_*` — your transport (SES / Mailgun / SMTP). Mail is env-driven; no code
     change to switch providers.
2. Confirm `APP_ENV=production` and `APP_DEBUG=false` (the debug page leaks SQL,
   env and cookies — never enable it in prod).

## Bring it up (reference stack)
`docker-compose.prod.yml` runs the whole stack locally in a prod-like shape (app,
web, scheduler, queue, mysql, redis, minio). In a real deployment the datastores
are usually managed services — point the env at them and delete those services.

```bash
cp .env.production.example .env      # fill every CHANGEME
docker compose -f docker-compose.prod.yml up -d --build
```

First boot migrates, syncs permissions and caches config automatically. The app is
served on `:80`.

> **Updating to a new build:** the `app-code` volume is seeded from the image only
> while empty, so after `--build` you must recreate it to pick up new code:
> `docker compose -f docker-compose.prod.yml down` then `docker volume rm
> <project>_app-code` (or `down -v` to reset all data). In a real orchestrator
> (ECS/K8s) this caveat disappears — each task/pod runs the image directly.

## Seed the demo (optional)
For a demo tenant + admin (`admin@cipherlearn.test` / `password`) and a full
catalogue:
```bash
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --force
```

## Health checks
- `/up` — Laravel's built-in health route (wire your load balancer here).
- `/healthz` — liveness. `/readyz` — readiness (DB/cache reachable).

## Background work
- **Scheduler** dispatches (see `bootstrap/app.php`): `outbox:work` (every minute,
  HRIS write-back drain), `hris:sync-employees --all` (nightly), `audit:verify
  --all` (nightly integrity check — a non-zero exit is your alert), and
  `notifications:overdue` (daily overdue nudges).
- **Queue worker** processes queued notifications (email + in-app). Scale with
  `--scale queue=N`.
- Both are guarded `withoutOverlapping()` + `onOneServer()`, so in a multi-node
  deployment exactly one node runs each scheduled job.

## Mail in development
The dev/demo stack (`docker-compose.yml`) includes **Mailpit**: every email the app
sends is captured and viewable at <http://localhost:8025> (SMTP on 1025). Point mail
at it with `MAIL_MAILER=smtp`, `MAIL_HOST=mailpit`, `MAIL_PORT=1025` (already the
default in `.env.example`).

## Operational commands
```bash
php artisan permissions:sync            # re-grant permissions across all tenants
php artisan audit:verify --all          # verify every tenant's audit chain
php artisan hris:sync-employees --all   # pull the workforce from the HRIS
php artisan outbox:work                 # drain the training-completion write-back outbox
php artisan notifications:overdue       # send overdue-training reminders
```
