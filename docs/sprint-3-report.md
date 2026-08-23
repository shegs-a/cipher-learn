# Sprint 3 Report — Identity, RBAC & Access Control

**Status:** complete · PR [#6](https://github.com/shegs-a/cipher-learn/pull/6) ·
CI green on MySQL 8 / PHP 8.3 · **106 tests**.

Built *before* the assignment engine on purpose, so that engine (Sprint 4)
inherits permission boundaries rather than retrofitting them.

## Delivered

**Identity & authorization**
- `employees.user_id` (nullable) links an HR-owned Employee to a login;
  `users.is_platform_admin` for the cross-tenant operator.
- `spatie/laravel-permission` with the teams feature scoped by `tenant_id`. The
  published migration was edited so the morph key (`model_id`) and team key are
  `char(26)` ULIDs, not the stub's `unsignedBigInteger`.
- `TenancyTeamResolver` pulls the permission team from `App\Support\Tenancy`, so
  web, Filament, console and queue are all scoped with one wire.
- `Gate::before` super-admin bypass; `canAccessPanel` gated by permission.
- `AuthorizesViaPermissions` trait gates the catalogue + People resources. The
  **Content-Administrator acid test** holds at model and UI layers.
- Five starter roles seeded per tenant.

**Admin UX (people-centric)**
- **LMS Admins** (renamed from Users): employees holding an elevated role, with
  their department and roles.
- **Roles → "Create LMS admin"**: pick a role + pick an employee (directory
  dropdown); `ElevateEmployeeToRole` provisions the login and assigns the role.
  Employees stay HR-owned; the User is the auth vehicle.

**Unified login + web portal**
- One Livewire `/login` on the `web` guard, routing by permission (admin → panel,
  else → learner portal); Filament's per-panel login disabled. Rate-limited,
  inactive users rejected, session password-hash set so `/admin` holds after a
  login made outside the panel.
- Desktop learner portal restyled to the approved design (sidebar, stat tiles,
  two-column cards with the "why you were assigned this" hero).

**Data protection**
- Passwords bcrypt-hashed (test-verified). Storage-level encryption → Sprint 8;
  no application field encryption, deliberately (see `sprint-3-data-protection.md`).

## Decisions worth remembering
- **Employees stay in the employees table** (HR owns them); a User is just the
  auth vehicle. Admins think in terms of elevating a *person*.
- **Web-first** going forward; mobile/responsive after the app is functional.

## Bugs the close surfaced (both invisible on sqlite, real on the prod stack)
- Filament `AuthenticateSession` bounced a unified-login sign-in from `/admin` →
  fixed by storing the session password-hash on login.
- spatie's permission cache isn't reset by `RefreshDatabase`; on CI's shared
  Redis a stale collection caused `PermissionDoesNotExist` → fixed by forgetting
  it in `TestCase::setUp`.

## Non-goals (deferred by design)
SSO / OIDC (structure allows JIT provisioning) · email-invite flow for elevated
employees (admin sets an initial password for now) · storage-level encryption
(Sprint 8) · application field encryption (documented no) · mobile/responsive
polish · assignment rules & performance data (Sprint 4).

## Verification
Raw artefacts in `docs/sprint-3-close-checks.md`: `SHOW CREATE TABLE
model_has_roles` on MySQL (`char(26)` keys + ULID round-trip), the acid test, and
tenant isolation. Full suite / Pint / PHPStan all green; CI runs Pest on MySQL 8.
