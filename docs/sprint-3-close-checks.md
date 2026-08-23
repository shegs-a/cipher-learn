# Sprint 3 — Close Verification (raw results)

Branch `sprint-3-rbac`. Identity, RBAC & access control. Run 2026-07-24.

The three raw artefacts the plan asks for at close: the `char(26)` proof on
MySQL, the Content-Administrator acid test, and role tenant-isolation.

---

## 1. `char(26)` morph key proven on MySQL 8

The load-bearing risk of the sprint: spatie's published migration types the morph
key and team key as `unsignedBigInteger`, but our PKs are `char(26)` ULIDs. We
edited the migration; this is the raw structure on MySQL 8.

```
$ SHOW CREATE TABLE model_has_roles\G   (via PDO)

CREATE TABLE `model_has_roles` (
  `role_id` bigint unsigned NOT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` char(26) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tenant_id` char(26) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`tenant_id`,`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
  KEY `model_has_roles_role_id_foreign` (`role_id`),
  KEY `model_has_roles_team_foreign_key_index` (`tenant_id`),
  CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

`model_id` (the morph key, holds `users.id`) and `tenant_id` (the team key, holds
`tenants.id`) are **`char(26)`**. spatie's own `role_id` correctly stays `bigint`.

**Data round-trip** — the seeded admin's role row, proving a ULID actually stores
and matches (a bigint column would have truncated it):

```
model_id=01ky9vpy99wc541ar9dg4y1xz9 | tenant_id=01ky9vpy0t6shpa2dkxgdj1s7t | role_id=1
admin user id: 01ky9vpy99wc541ar9dg4y1xz9   ← equals model_id: the char(26) morph resolves
```

---

## 2. The acid test + tenant isolation (raw)

```
$ php artisan test --filter="RolePermissionTest|PanelAccessTest"

   PASS  Tests\Feature\Rbac\PanelAccessTest
  ✓ it keeps a learner-only user out of the admin panel                  0.39s
  ✓ it lets a Content Administrator into the panel                       0.03s
  ✓ it is the acid test in the UI: Content Administrator sees courses,…  0.03s
  ✓ it lets an L&D Manager see people                                    0.03s
  ✓ it gives a platform super-admin everything via the Gate::before byp… 0.01s
   PASS  Tests\Feature\Rbac\RolePermissionTest
  ✓ it assigns a role and resolves permissions through the char(26) mor… 0.04s
  ✓ it is the acid test: Content Administrator sees nothing about peopl… 0.03s
  ✓ it scopes roles per tenant — a role in Tenant A grants nothing in T… 0.05s
  ✓ it seeds Content Administrator with no forbidden permissions in the… 0.04s

  Tests:    9 passed (27 assertions)
```

- **Acid test** (two layers): a Content Administrator can author courses/paths and
  gets false/403 on enrollments, reports, employees, users and roles.
- **Tenant isolation**: a role granted in tenant A confers nothing in tenant B.
- **Super-admin**: `is_platform_admin` passes every gate via `Gate::before`, with
  no role.

---

## 3. Full gates

```
$ php artisan test          →  Tests: 106 passed (333 assertions)
$ ./vendor/bin/pint --test  →  {"tool":"pint","result":"passed"}
$ ./vendor/bin/phpstan analyse (mem 1G)  →  [OK] No errors
```

CI runs the same Pest suite against **MySQL 8 / PHP 8.3**.

---

## 4. Password hashing (data-protection deliverable)

```
$ php artisan test --filter=PasswordHashingTest
  ✓ it stores passwords hashed, never in plaintext

  Tests:    1 passed (3 assertions)
```

Asserts a stored password is never the plaintext, `Hash::check` verifies it, and
the stored value is a bcrypt hash. See `docs/sprint-3-data-protection.md` for the
full posture (storage-level encryption → Sprint 8; no field encryption, and why).

---

## Summary

| Check | Result |
|-------|--------|
| `model_has_roles` morph/team keys | **`char(26)`** on MySQL 8; ULID round-trips and matches |
| Content-Admin acid test | passes (model + UI layers) |
| Role tenant isolation | passes |
| Platform super-admin bypass | passes, not a role |
| Full suite / Pint / PHPStan | 106 passed · passed · no errors |
| Password hashing | bcrypt, verified |
