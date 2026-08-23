# Sprint 3 Plan — Identity, RBAC & Access Control

> Status: draft for review. **Renumbered from "Sprint 2.5" to Sprint 3**; the
> former Sprints 3–7 shift up by one (assignment engine → 4, learner journey → 5,
> write-back + manager view → 6, AI → 7, deploy + demo → 8).
>
> Sits **before** the assignment engine (now Sprint 4) by design — that engine must
> be built with permission boundaries already enforced, not retrofitted into
> working code. Sprint 2 (HRIS port + sync) assumed merged.

## Objective
One login for everyone. Role-based access decides which portal you reach and
what you can do inside it. Permissions are the atomic unit; roles are named
bundles. Everything is **tenant-scoped** — a role in Tenant A grants nothing in
Tenant B. Plus a user & role management surface, and an honest data-protection
baseline.

No assignment logic, no performance data — that's Sprint 4.

## The load-bearing open decision — settle this at kickoff

**How do `users` and `employees` relate?** Sprint 1 left both tables. `enrollments`
reference `employee_id` (who learns) *and* `assigned_by_user_id` (who assigned).
So the split already exists implicitly and must now be made explicit:

- **`User`** = an authenticatable identity that holds roles and logs in.
- **`Employee`** = the HRIS-mirrored person who receives enrollments.

**Recommendation:** add `employees.user_id` — a **nullable** `char(26)` FK to
`users`. Nullable because an employee synced from the HRIS may not yet have
logged in or been provisioned. Roles attach to **`User`**, never to `Employee`.
When a learner logs in, their `User` resolves to their `Employee` to show their
learning. SSO (later) does just-in-time `User` creation and links to the
`Employee` by email/external id.

Why this matters: RBAC attaches to whatever is authenticatable. If we get this
link wrong, "show me my assigned courses" has no clean path from the logged-in
identity to the enrollment rows. **Confirm this model before writing any
migration.**

## Package — do not hand-roll this
**`spatie/laravel-permission`**, tenant-scoped via its **teams** feature. Roles,
permissions, `@can`, policies, Filament integration — all standard, all tested.
Hand-rolled permission systems are where privilege-escalation bugs live.

### 🚨 The gotcha that will silently break everything
Spatie's published migration uses **`unsignedBigInteger`** for the morph key
(`model_id`) and the team key. **Our PKs are `char(26)` ULIDs.** If the default
migration runs unedited:

- `model_has_roles.model_id` won't match `users.id` → the morph relation breaks
- the team key won't match `tenants.id`

**Before migrating, edit the published migration so `model_id` and the team
foreign key are `char(26)`.** This is the same class of error as the Sprint 1
FK-type mismatch — invisible in a summary, fatal in the raw schema. Verify it
with `SHOW CREATE TABLE model_has_roles\G` at sprint close.

### Tenant scoping — the integration point
- `config/permission.php`: `'teams' => true`, team key = `tenant_id`.
- The **same middleware that sets the current tenant** must also call
  `setPermissionsTeamId($tenant->id)`. If tenant context is set but the
  permission team id is not, checks silently read the wrong team's roles.
- This must fire for **web requests, Filament panels, queued jobs, and console
  commands** — every path Sprint 4's engine and Sprint 6's workers run in.
  A permission check with no team context set is the leak.

## Unified login + portal routing
- **One `/login`** (Livewire), authenticating the single `web` guard against
  `users`. Disable Filament's per-panel login page so there is exactly one door.
- Post-auth, redirect by permission: default to the learner portal, with a
  visible **portal switcher** to any panel the user's roles permit.
- **An identity can hold roles in several portals at once** (a Content Admin who
  is also a learner). Model "which portal" as *"which panels can this user
  reach"*, never as a user-type flag.
- Filament panel entry is gated by `canAccessPanel(Panel $panel): bool` on the
  `User` model (Filament's `FilamentUser` contract) → checks the relevant
  permission. Learner/manager Livewire routes gated by middleware on the same
  permissions.

## Permission set (atomic) and starter roles (bundles)

**Permissions** — the vocabulary the whole app checks against:
```
courses.view          courses.manage
learning_paths.view   learning_paths.manage
enrollments.view      reports.view
users.view            users.manage
roles.view            roles.assign
rules.manage          (competency rules — consumed Sprint 4)
```

**Starter roles**, per tenant:
| Role | Holds | Deliberately excludes |
|---|---|---|
| Tenant Admin | everything in-tenant | — |
| L&D Manager | courses.*, learning_paths.*, rules.manage, reports.view, enrollments.view | users.manage, roles.assign |
| **Content Administrator** | courses.manage, learning_paths.manage | **enrollments.view, reports.view, users.*, roles.\*** |
| Manager | reports.view + enrollments.view **scoped to own reports** | course/user management |
| Learner | own learning only | everything admin |

**Content Administrator is the acid test of this whole sprint.** It must be able
to build courses and paths and see *nothing* about who took them, who failed, or
who anyone is. If that role can reach a learning-activity report, RBAC is wrong.

### Platform super-admin — do NOT model as a team role
A cross-tenant operator (you, running the platform) cannot be a team-scoped role,
because team roles grant nothing outside their tenant. Model it as a
`users.is_platform_admin` boolean plus a `Gate::before()` bypass. Keep it out of
the tenant role system entirely so it can't be assigned by a Tenant Admin.

## Filament surface — Users & Roles management
- **Users** resource: list/invite/deactivate, assign roles. Gated `users.manage`.
- **Roles** resource: view roles and their permissions, assign roles to users.
  Gated `roles.assign`. Creating *new* roles or editing permission sets →
  `roles.manage` (Tenant Admin only), so a delegated user-manager can assign
  existing roles but not invent a role that escalates privilege.
- Both resources **tenant-scoped** — a Tenant Admin manages only their tenant's
  users and roles.

## Data protection — separate the real from the reassuring
- **Password hashing:** confirm the `hashed` cast is on `User` (Laravel default =
  bcrypt). Verify, don't build. Optionally raise bcrypt rounds to 12. A test
  asserts stored passwords are never plaintext and `Hash::check` passes.
- **Encryption at rest, two distinct things — don't conflate:**
  - *Storage-level* (DB volume + S3/OBS bucket encrypted by infra) → a **deploy
    setting, Sprint 8**, not app code. Satisfies most audit checkboxes.
  - *Application-level field encryption* (`encrypted` cast) → **selective only.**
    An encrypted column can't be indexed or searched, so it cannot go on `email`
    or anything looked up. **Recommendation: encrypt nothing this sprint** — the
    schema mirrors HRIS data and owns no salary/national-ID/health fields that
    warrant it. Document the decision explicitly rather than wrapping columns and
    breaking search. Revisit if/when a truly sensitive field is added.

Tell the CTO "storage-level at deploy + confirmed password hashing, and here's
why we deliberately haven't field-encrypted yet" — that's stronger than a vague
"everything's encrypted."

## Tests (Pest — MySQL 8 in CI)
- **The acid test:** a Content Administrator gets 403 on enrollments, reports,
  users and roles surfaces, and 200 on course/path management.
- **Tenant isolation of roles:** a user with a role in Tenant A has no
  permissions in Tenant B — and a permission check with the wrong team id set
  does not leak.
- `canAccessPanel` gates each Filament panel by permission.
- Unified login routes a user to a portal they can actually access; a
  learner-only user cannot reach the admin panel.
- Platform super-admin bypass works and is **not** assignable via the Roles UI.
- Password is hashed at rest; `Hash::check` verifies.
- `model_has_roles.model_id` is `char(26)` and the morph resolves to a `User`.

## Explicit non-goals (deferred by design)
SSO / OIDC / SAML → later (structure `User` to allow JIT provisioning, don't
build it) · storage-level encryption → Sprint 8 · field encryption → not now
(documented) · assignment rules & performance data → Sprint 4 · audit log of
permission changes → nice-to-have, Sprint 6+ · self-service password reset UI →
framework default is fine, don't customise.

## Suggested build order
1. Settle the `users`↔`employees` link; migration for `employees.user_id`.
2. Install spatie/laravel-permission; **edit the published migration to
   `char(26)`**; enable teams keyed on `tenant_id`.
3. Wire `setPermissionsTeamId` into the tenant-resolution middleware — web,
   Filament, queue, console. This is the load-bearing step.
4. Seed permissions + starter roles (incl. Content Administrator).
5. `canAccessPanel` + unified `/login` + portal routing + switcher.
6. Filament Users & Roles resources, gated.
7. Confirm password hashing; document the encryption decision.
8. Tests — lead with the Content Administrator acid test and tenant isolation.
9. Sprint close: raw `SHOW CREATE TABLE model_has_roles\G`, the acid-test output,
   and the tenant-isolation test result — as raw terminal, not a summary.

## Rough size
~5–7 focused sessions. Steps 2–3 are where the risk concentrates; if the team
scoping is wrong, everything above it is quietly insecure. New dep: **one**
(spatie/laravel-permission).

## Open decisions (recommendation in each)
- **(a)** `employees.user_id` nullable FK vs `users.employee_id` → *`employees.user_id`;
  an employee exists before a login does, not the reverse.*
- **(b)** Platform super-admin as `Gate::before` bypass vs a role → *bypass; never
  a team role.*
- **(c)** Field-encrypt anything now → *no; document why.*
- **(d)** Portal switcher in v1 vs default-portal-only → *include it; multi-role
  identities are real and a dead-end landing is a bad first impression.*
