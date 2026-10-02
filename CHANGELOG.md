# Changelog

All notable changes to CipherLearn are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html):
given a version `MAJOR.MINOR.PATCH`, we bump **MAJOR** for incompatible changes,
**MINOR** for backwards-compatible features, and **PATCH** for backwards-compatible
fixes. Each release corresponds to a git tag; unreleased work accumulates under
_Unreleased_ until the next tag.

## [Unreleased]

### Added

- **Leave-aware assignment** (issue #1, Sprint 13) — a tenant policy,
  _Allow admins assign courses and learning paths to employees on leave_ (off by
  default), enforced in the shared assignment services so it covers individual,
  bulk, department, organisation-wide and learning-path assignment, and any future
  workflow that uses them. A blocked employee gets no enrolment, no path membership
  (never a partial path) and no notification; the initiating admin gets one notice
  (one summary for a bulk run). Bulk runs never fail because someone is on leave and
  report assigned / blocked / skipped separately.
- **Leave data via the HRIS port** — `HrisLeaveSource` / `LeaveData`, mirrored into a
  local `employee_leaves` table at 06:00 and 18:00 in each tenant's own timezone
  (new required `tenants.timezone`), plus an admin **Sync leave now** button.
  Assignment reads only the local table, so an HR-system outage cannot block it; stale
  data fails open and is flagged in the audit trail.
- **Sync reporting** — every sync run records its trigger (scheduled slot / manual +
  who), counts and duration in Sync history; failures notify admins; a dashboard stat
  shows leave-data freshness.
- **Auditability** — `assignment.blocked`, `assignment.allowed_on_leave`,
  `assignment.bulk_completed` and `settings.assignment_policy_changed` events.
- Bulk **Assign course / Assign learning path** actions on the Employees table.

### Changed

- `AssignCourse::attempt()` / `AssignLearningPath::attempt()` return a structured
  result; `handle()` keeps returning the model but throws `AssignmentBlockedException`
  when policy blocks. `AssignCourseOrgWide` now returns an `AssignmentResult` rather
  than a count.

_Still planned for V2: the performance-gap rules engine + AI recommender (as a hosted
service), single-tenant / on-prem mode + SSO, audit hardening (DB-level WORM,
HMAC-keyed chain, allowlist redaction), and path-level certificates._

## [1.0.0] - 2026-08-04

First complete release: a standard, HRIS-native LMS, production-deployable. Built
over Sprints 0–12 (each a reviewed PR to `main` with CI green on MySQL 8 / PHP 8.3).
243 tests; PHPStan level 6 and Pint clean.

### Added

- **Foundation & platform** — Containerised Laravel 12 (PHP 8.3) with Docker Compose
  (app, nginx, MySQL 8, Redis, Minio); CI running Pest, PHPStan L6 and Pint; JSON
  request logging with per-request correlation ids; `/healthz` + `/readyz` health
  checks. _(Sprints 0–1)_
- **Multi-tenancy** — Row-level tenancy via a `BelongsToTenant` global scope, ULID
  keys throughout, and tenant context bound from the authenticated user on every
  request. _(Sprint 1)_
- **HRIS integration** — A capability-segregated HRIS port (read + write-back) with a
  deterministic mock adapter and a docs-only ExampleHR adapter; idempotent,
  self-healing `hris:sync-employees` with a mass-exit guard. Employees are HR-owned
  (never created in-app). _(Sprint 2)_
- **Identity, RBAC & access control** — spatie/laravel-permission with per-tenant
  teams; a unified login that routes by permission; people-centric admin ("LMS
  Admins" elevated from the Roles screen). _(Sprint 3)_
- **Assignment & discovery** — Manual assignment carrying a written rationale the
  learner and manager see; catalogue browsing with request→approval. _(Sprint 4)_
- **The learning journey** — Course player (lessons), server-side graded quizzes with
  an attempt ceiling, completion, and certificates (printable + a public
  `/verify/{serial}`); completion written back to the HR record via a transactional
  outbox. _(Sprint 5)_
- **Admin overview dashboard** — Tenant-scoped KPI tiles and charts (headcount,
  coverage, completion/pass rates, overdue, recent sync runs), permission-gated.
  _(Sprint 6)_
- **Reports & export** — Filterable, tenant-scoped reports (enrolments, completions,
  certificates, overdue) with streamed CSV export. _(Sprint 7)_
- **Notifications** — Queued in-app + email notifications for assignment, approval,
  rejection, completion, and overdue reminders; in-app bell. _(Sprint 8)_
- **Learning paths** — Ordered course bundles with fan-out enrolment, direct
  self-enrol, and live path progress; authoring with drag-to-reorder. _(Sprint 9)_
- **Tamper-evident audit trail** — Append-only, per-tenant SHA-256 hash chain with a
  gapless sequence; automatic model auditing plus explicit domain events (auth,
  exports, role changes); an `audit:verify` command; a read-only admin view with a
  live per-row integrity check; secret redaction with a build-time tripwire.
  _(Sprint 10)_
- **Responsive learner portal** — A single shared responsive shell: desktop sidebar
  and a mobile bottom tab bar; every portal page usable at 375px. _(Sprint 11)_
- **Production packaging & operations** — Scheduler wiring for the operational
  commands (`outbox:work`, `hris:sync-employees`, `audit:verify`,
  `notifications:overdue`); a `permissions:sync` command that rolls grants out to
  every tenant; release automation in the container entrypoint (migrate + permission
  sync + prod config cache); a production Compose stack (app, web, scheduler, queue
  worker); env-driven mail with Mailpit for the demo; `.env.production.example` and
  a `docs/DEPLOY.md` runbook. _(Sprint 12)_

### Notes

- Deliberately deferred to V2: the performance-data rules engine and AI recommender
  (the product differentiator, to be built as a hosted service for IP protection),
  single-tenant / on-prem mode, real SSO, path-level certificates, and the audit
  hardening items above.

[Unreleased]: https://github.com/shegs-a/cipher-learn/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/shegs-a/cipher-learn/releases/tag/v1.0.0
