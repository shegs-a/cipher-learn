# Sprint 10 Plan — Audit Trail (robust, tamper-evident)

> Status: confirmed at kickoff. **Scope (owner, 2026-08-03): audit trail ONLY this
> sprint; mobile/responsive moves to its own sprint before deploy.** Approach
> confirmed: **purpose-built** (not a package), **append-only + per-tenant hash
> chain + `audit:verify`**. DB-level WORM triggers are a **V2** hardening (recorded
> in memory), not this sprint. Part 2 (responsive) below is deferred — kept for the
> next sprint's plan.

## Part 1 — Audit trail (the emphasis: "very very robust")

### Objective
An answer to *who did what, to what, when, and from where* — for every
security- and compliance-relevant action — that is **complete, tenant-isolated,
append-only, and tamper-evident**. Not a debug log: an evidentiary record.

### What "robust" means here (design pillars)
1. **Append-only + immutable.** An `audit_logs` row is never updated or deleted.
   The model blocks `updating`/`deleting` (throws), so even application code can't
   quietly rewrite history.
2. **Tamper-evident hash chain.** Each entry stores `hash = sha256(previous_hash ‖
   canonical(entry))`, chained **per tenant**. Any insertion, deletion or edit
   breaks the chain, and a `audit:verify` command (and a model method) re-computes
   it to detect tampering. This is the core of "very robust" — you can *prove* the
   log hasn't been altered.
3. **Complete coverage, two ways:**
   - **Automatic model auditing** — an `Auditable` trait (Eloquent observer) logs
     `created` / `updated` / `deleted` on the key records (enrolments,
     certificates, courses, lessons, quizzes, learning paths, path enrolments,
     users, roles/permissions, tenants) with the **changed attributes (old → new)**.
   - **Explicit domain events** — a central `Auditor` service logs the actions that
     aren't a single model write: **login / logout / failed login**, course
     assignment, request **approve/reject**, course completion, certificate issue,
     **role/permission grants**, **report CSV exports**, HRIS **sync runs**, outbox
     processing, and self-enrolment.
4. **Full context per entry:** actor (user id + name + role, or `system`/console),
   tenant, event, the auditable (morph), old/new values, **IP, user-agent,
   request-id**, and an immutable `created_at`.
5. **Tenant-isolated** (BelongsToTenant) — one tenant can never see or affect
   another's audit log; the hash chain is per-tenant.
6. **Safe by construction** — sensitive/`$hidden` attributes (passwords, tokens,
   `correct_keys`) are **redacted** from old/new snapshots; nothing secret lands in
   the audit trail.

### Schema — `audit_logs`
`id` (ULID) · `tenant_id` · `event` (e.g. `enrollment.updated`, `auth.login`,
`report.exported`) · `auditable_type`/`auditable_id` (nullable morph) ·
`actor_id`/`actor_type` (nullable — null = system) · `actor_label` (denormalised
name at the time) · `actor_roles` (json) · `old_values`/`new_values` (json) ·
`context` (json: ip, user_agent, request_id, url) · `previous_hash` · `hash` ·
`created_at`. Indexed on `(tenant_id, created_at)` and `(auditable_type,
auditable_id)`. No `updated_at` — entries never change.

### Surfaces
- **`Auditor` service** (`app(Auditor::class)->log(event, auditable?, old?, new?)`)
  — the one writer; resolves actor + context, computes the hash, appends. Runs the
  append in a small locked transaction so concurrent writes chain correctly.
- **`Auditable` trait** — drop onto a model to auto-audit its writes via the Auditor.
- **`audit:verify {--tenant=|--all}`** console command — re-walks the chain and
  reports the first broken link (or clean).
- **Filament read-only `AuditLogResource`** (Administration group, gated a new
  `audit.view` permission → Tenant Admin only) — the searchable/filterable trail
  (by event, actor, date, auditable), with each entry's before/after diff. A
  per-record "activity" relation on audited resources is a nice-to-have.
- **A new permission `audit.view`** in the seeder (Tenant Admin; *not* L&D/Content).

### Tests (Pest — MySQL 8 in CI)
- A model update writes one audit entry with the exact changed attrs (old→new);
  sensitive attrs redacted; a delete/create likewise.
- Explicit events (login, assign, approve/reject, export, completion) each log.
- **Append-only:** updating or deleting an `AuditLog` throws.
- **Hash chain:** entries chain; `audit:verify` passes on an untouched log and
  **fails** when a row is force-mutated in the DB (tamper detected).
- Tenant isolation: one tenant's actions never appear in another's log or chain;
  system/console actor recorded when unauthenticated.
- Gating: only `audit.view` sees the resource (acid test extended).

## Part 2 — Mobile / responsive
The learner portal + admin were built desktop-first (per the UI decision). Make
them work on phones/tablets:
- **Learner portal**: the sidebar collapses to a top bar + drawer on small screens;
  stat tiles, course cards (incl. the thumbnail + "why" panel), the course player,
  quiz, certificates and paths reflow to a single column; tap targets sized.
- **Admin (Filament)** is already responsive out of the box — verify the custom
  report pages + widgets reflow; fix any overflow (wide tables scroll within their
  own container, never the page).
- Verified at 375px (mobile), 768px (tablet), 1280px (desktop).

## Suggested build order
1. `audit_logs` migration + `AuditLog` model (append-only + hash) + `Auditor`
   service + tests (the tamper-evident core).
2. `Auditable` trait on the key models + explicit domain-event calls at the actions.
3. `audit:verify` command + Filament `AuditLogResource` + `audit.view` permission.
4. Responsive pass on the learner portal (+ verify admin).
5. Close: browser walkthrough at 3 widths, isolation/tamper checks, report, PR.

## Rough size
Larger than a usual sprint — the audit trail alone is a full slice (tamper-evident
storage + coverage + UI), and the responsive pass touches every portal view.
**New deps: none** (native hashing; no audit package — see (a)).

## Open decisions (recommendation in each)
- **(a) Build vs package** (owen-it/laravel-auditing / spatie/activitylog) →
  *build.* The packages capture model changes well but give us **no tamper-evidence
  (hash chain), no per-tenant chain, and not our ULID/tenancy conventions** —
  exactly the "very robust" parts. A purpose-built ~one-table system is small,
  fully tested, and matches how the HRIS port / outbox were built.
- **(b) Tamper-evidence: hash chain vs plain append-only** → *hash chain.* It's
  what lets us *prove* integrity (`audit:verify`), the point of "very very robust".
  (A DB-level append-only trigger is a later hardening; app-level immutability +
  the chain is the pragmatic strong default.)
- **(c) Coverage: auto model-audit + explicit events vs explicit-only** → *both* —
  auto-audit catches everything that touches the key tables; explicit events name
  the actions (login, export) that aren't a single write.
- **(d) Scope split** — if the combined sprint is too big, ship **audit trail this
  sprint, responsive next** (deploy still last). Recommend keeping both but I'll
  flag early if the audit work alone fills the sprint.

## Explicit non-goals (deferred)
DB-level append-only triggers / WORM storage · shipping audit logs to an external
SIEM · signed/exportable audit reports (PDF) · user-facing "your activity" view ·
audit-log retention/rotation policy → later/ops. Real responsive *design* overhaul
(this is a reflow pass, not a redesign).
