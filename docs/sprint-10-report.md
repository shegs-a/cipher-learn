# Sprint 10 Report — Tamper-Evident Audit Trail

**Status:** feature-complete · **235 tests** (was 212 at S9 close; +23) · Pint +
PHPStan (L6) clean on PHP 8.3. Scope was **narrowed to the audit trail alone** at
kickoff — mobile/responsive becomes its own next sprint (owner decision:
"audit now, responsive next").

## Objective
A **very robust** audit trail: not just a log, but a **tamper-evident** record —
one that can prove to a customer, an auditor, or ourselves that history has not
been quietly rewritten, even by someone with database access on infrastructure we
don't control.

## Scope (owner-confirmed at kickoff)
- **Purpose-built, tamper-evident** — not an off-the-shelf logging package.
- Tamper-evidence = **append-only + per-tenant hash chain + an `audit:verify`
  command**. **DB-level WORM triggers deferred to V2** (captured in memory), since
  a hostile DBA on the customer's own infrastructure is a V2 hardening concern.
- **Audit trail only this sprint**; responsive is its own sprint before deploy.

## How the tamper-evidence works
Every entry is one link in a **per-tenant hash chain**:

```
hash = sha256( canonical(row fields) ‖ previous_hash )
```

- **Append-only** — the `AuditLog` model throws on update/delete, and the table
  carries no `updated_at`. App code cannot rewrite history.
- **Gapless sequence** — appends are serialised per tenant under a cache lock, so
  sequences run 1, 2, 3… with no gaps. A deleted or inserted row shows up as a gap.
- **Chained hashes** — each row folds in the previous row's hash, so editing any
  early row invalidates **every** row after it. A tamperer would have to rewrite
  the entire tail *and* forge each hash — and `audit:verify` still catches a broken
  link or a bad sequence.
- **Deterministic hashing** — JSON payloads are stored as `longText` (not MySQL
  `json`, which re-orders keys and would break the byte-for-byte hash) and
  recursively key-sorted before hashing, so a re-decoded row hashes identically.

## Delivered
- **`audit_logs` + `AuditLog`** — append-only model; `hashFor()` / `computeHash()`
  / `hasValidHash()` are the single source of truth for both writing and verifying,
  so writer and verifier can never disagree.
- **`Auditor`** — the one writer. Resolves the actor (user *or* system/console) and
  request context (ip, user-agent, request id, url/method), **redacts secrets**
  (password, tokens, HRIS settings, quiz answer keys), and appends under a
  per-tenant lock.
- **Automatic model auditing** (`Auditable` trait) — captures create/update/delete
  with the old→new diff on the domain models: Enrollment, Certificate,
  PathEnrollment, Course, Lesson, Quiz, Question, LearningPath, Employee, User.
- **Explicit domain events** where no single model write would capture them:
  `auth.login` / `auth.login_failed` / `auth.logout`, `report.exported` (a bulk
  read leaving the system), `rbac.role_granted` / `rbac.roles_synced` (privilege
  changes that write spatie's pivot, not an audited model).
- **`audit:verify {--tenant=|--all}`** — re-walks the chain(s) and exits non-zero,
  naming the tenant/row/reason, if any chain has been altered. For scheduling and
  for pre-export trust.
- **Read-only `AuditLogResource`** (Administration, gated by the new **`audit.view`**
  — Tenant Admin only) — tenant-scoped list with a **live per-row integrity shield**
  (green = hash still valid, red = altered), a before/after diff view, filters by
  event and date, and **no** create/edit/delete/bulk path.

## Tests (+23 → 235)
- **Core (6)** — genesis hash, chaining, append-only throws, tamper detection,
  per-tenant independent chains, secret redaction.
- **Coverage (5)** — auto CRUD audit + old→new diff, no entry on a no-op update,
  successful login on the tenant chain, failed login on the platform chain **with
  the password absent**, role grant recorded.
- **Verify command (6)** — intact pass, `--all` sweep, and failure on an in-place
  tamper, a mid-chain delete, and a genesis delete; empty-trail nothing-to-verify.
- **Resource (4)** — read-only, gated (admin yes / author no), listing, tamper→
  invalid shield.
- **RBAC acid test** strengthened: Content Administrator is denied `audit.view`
  (the trail exposes people/activity).

## Robustness notes
- The whole trail is byte-stable across MySQL and sqlite because JSON is stored
  verbatim and canonicalised only at hash time.
- Verification is read-only and never routes through Eloquent events, so it can't
  itself mutate the trail.

## Run it
```bash
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/pest
/opt/homebrew/opt/php@8.3/bin/php artisan audit:verify --all
# admin (Tenant Admin) → Administration › Audit trail
```

## Not built (deferred, by design)
- **DB-level WORM** (append-only enforced by database triggers/grants, so even a
  direct SQL `UPDATE`/`DELETE` is blocked, not merely *detected*) → **V2 hardening**,
  the answer to a hostile DBA on customer-owned infrastructure. Stored in memory.
- Off-box / external anchoring of the chain tip (e.g. periodic notarisation) → V2.
- Retention/archival policy for the trail → later.

## Next
**Sprint 11 — Mobile/responsive pass**, then **Deploy + demo** (scheduler + real
mail) to close V1.
