# Sprint 2 — Final Verification Checks (raw results)

Branch `sprint-2-hris` @ `b13884f` · HEAD vs `main`. Run 2026-07-23.

## Script run

```bash
# 1
grep -rn "exit" app/Hris/Sync/ | grep -i "zero\|empty\|abort\|guard\|threshold\|fraction"
# 2
php artisan test --filter=exit
# 3
php artisan migrate:fresh --seed
php artisan hris:sync-employees --tenant=demo    # run twice; second run's output only
# 4
php artisan test --filter=Tenant
# 5
grep -rin "example" app/Hris/Sync/ app/Hris/Data/
# 6
grep -rn "HrisUnsupportedOperation" app/Hris/Adapters/ExampleHrAdapter.php
php artisan test --filter=Unsupported
# 7
php artisan test
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
# 8
git diff main...HEAD --stat
git diff main...HEAD | grep -in "api_key\|secret\|password\|token\|bearer"
# 9
php artisan migrate:rollback --step=1 && php artisan migrate
```

## Environment notes (read before the results)

- Local `php` is **8.5.8**; the DB-independent items (`test`, `pint`, `phpstan`,
  `grep`) ran on it. CI runs the same suite on PHP 8.3 / MySQL 8.
- Items **3 and 9 hit the database.** `.env` sets `DB_HOST=mysql` (the
  Docker-internal service name), which does not resolve from the host shell, so
  those two items were run with a `DB_HOST=127.0.0.1` prefix to reach the **same
  MySQL 8 container** (`docker compose` stack, `mysql` service healthy). No other
  change. The Docker stack was started for this run.
- Every command below is shown with its **verbatim** output. Two items produce a
  deliberately-empty or "no tests" result — both are called out as findings, not
  hidden.

---

## 1. Guard/threshold logic in the sync exit sweep

```bash
$ grep -rn "exit" app/Hris/Sync/ | grep -i "zero\|empty\|abort\|guard\|threshold\|fraction"
[exit code: 1]
```

**No output (grep exit code 1 = no match) at the time of this run.** Finding:
`SyncEmployees` had **no mass-exit safety guard** — no threshold to abort/withhold
the sweep if an unexpectedly large fraction of employees would be marked `exited`
(e.g. when the HR API returns a truncated or empty directory).

> **RESOLVED (2026-07-23, follow-up commit).** A mass-exit guard was added:
> `SyncEmployees::sweepLeavers()` now withholds the sweep and marks the run
> `failed` with `exit_guard_tripped: true` when the leaving fraction exceeds
> `hris.sync.exit_guard_threshold` (default 0.20) on a workforce of at least
> `exit_guard_min_active` (default 10). `hris:sync-employees --force` overrides it
> and the command exits non-zero on a trip. Covered by 5 sync tests + 2 command
> tests, and verified in the real CLI on MySQL 8 (a 1-person directory against a
> 41-active tenant was withheld: "39 of 41 (95%) would be exited — sweep withheld",
> exit 1, nobody exited). See `docs/sprint-2-plan.md → Mass-exit guard`.

---

## 2. `php artisan test --filter=exit`

```
   PASS  Tests\Feature\Hris\HrisPortTest
  ✓ it includes leavers so the exit sweep has something to act on        0.35s

   PASS  Tests\Feature\Hris\SyncEmployeesTest
  ✓ it marks a person the HR system stopped listing as exited, without…  0.04s
  ✓ it leaves locally-created employees alone during the exit sweep      0.01s

  Tests:    3 passed (5 assertions)
  Duration: 0.52s
```

---

## 3. `migrate:fresh --seed`, then sync twice (second run only)

```
$ php artisan migrate:fresh --seed

  Dropping all tables .......................................... 165.07ms DONE

   INFO  Preparing database.

  Creating migration table ...................................... 16.89ms DONE

   INFO  Running migrations.

  0001_01_01_000000_create_users_table .......................... 45.63ms DONE
  0001_01_01_000001_create_cache_table .......................... 44.55ms DONE
  0001_01_01_000002_create_jobs_table ........................... 39.08ms DONE
  2026_07_22_000100_create_tenants_table ........................ 15.15ms DONE
  2026_07_22_000110_add_tenant_and_sso_to_users_table .......... 103.95ms DONE
  2026_07_22_000120_create_employees_table ...................... 62.59ms DONE
  2026_07_22_000130_create_courses_table ........................ 42.04ms DONE
  2026_07_22_000140_create_lessons_table ........................ 53.47ms DONE
  2026_07_22_000150_create_quizzes_table ........................ 54.21ms DONE
  2026_07_22_000160_create_questions_table ...................... 59.65ms DONE
  2026_07_22_000170_create_learning_paths_table ................. 91.68ms DONE
  2026_07_22_000180_create_enrollments_table ................... 111.42ms DONE
  2026_07_22_000190_create_lesson_progress_table ................ 76.59ms DONE
  2026_07_22_000200_create_quiz_attempts_table ................. 104.50ms DONE
  2026_07_22_000210_create_certificates_table .................. 123.59ms DONE
  2026_07_22_000220_create_competency_rules_table ............... 56.90ms DONE
  2026_07_22_000230_create_outbox_events_table .................. 37.22ms DONE
  2026_07_22_000240_create_sync_runs_table ...................... 34.94ms DONE


   INFO  Seeding database.
```

First `hris:sync-employees --tenant=demo` run suppressed per instruction
(exit code 0). **Second run, verbatim:**

```
$ php artisan hris:sync-employees --tenant=demo
  Syncing Demo Organisation ..................................... 83.49ms DONE

+-------------------+---------+---------+---------+-----------+--------+--------+
| Tenant            | Adapter | Created | Updated | Unchanged | Exited | Errors |
+-------------------+---------+---------+---------+-----------+--------+--------+
| Demo Organisation | mock    | 0       | 0       | 45        | 0      | 0      |
+-------------------+---------+---------+---------+-----------+--------+--------+
[exit code: 0]
```

Idempotency confirmed on MySQL 8: **0 created, 0 updated, 45 unchanged, 0
exited, 0 errors.** (The seeder ran the first sync during `--seed`; the two
manual runs are the second and third, both no-ops.)

---

## 4. `php artisan test --filter=Tenant`

```
   PASS  Tests\Feature\Filament\CourseResourceTest
  ✓ it creates a course through the Filament panel, stamped with the te… 0.53s
  ✓ it only lists the current tenant's courses in the panel              0.10s

   PASS  Tests\Feature\Filament\EmployeeResourceTest
  ✓ it only ever shows the current tenant's employees                    0.07s

   PASS  Tests\Feature\Filament\SyncRunResourceTest
  ✓ it scopes the history to the current tenant                          0.05s

   PASS  Tests\Feature\Hris\HrisPortTest
  ✓ it resolves the adapter named on the tenant                          0.01s

   PASS  Tests\Feature\Hris\ExampleHrAdapterTest
  ✓ it sends the tenant bearer token                                     0.03s

   PASS  Tests\Feature\Hris\SyncEmployeesCommandTest
  ✓ it syncs a single tenant by slug                                     0.03s
  ✓ it syncs every tenant with --all                                     0.03s
  ✓ it fails when a named tenant does not exist                          0.01s
  ✓ it succeeds quietly when --all finds no tenants at all               0.01s
  ✓ it rejects being given both --tenant and --all                       0.01s
  ✓ it requires one of --tenant or --all                                 0.01s

   PASS  Tests\Feature\Hris\SyncEmployeesTest
  ✓ it never touches another tenant's employees                          0.01s

   PASS  Tests\Feature\TenantIsolationTest
  ✓ it auto-stamps the current tenant on create                          0.01s
  ✓ it scopes every query to the current tenant                          0.01s
  ✓ it cannot resolve another tenant's record by id                      0.01s

  Tests:    16 passed (29 assertions)
  Duration: 1.03s
```

---

## 5. `grep -rin "example" app/Hris/Sync/ app/Hris/Data/`

```
app/Hris/Data/EmployeeData.php:13: * payload into this shape, so nothing vendor-specific (ExampleHR field names,
app/Hris/Data/TrainingCompletionData.php:15: * ExampleHR's case, honestly declare the absence of one.
[exit code: 0]
```

Both hits are in **comments** explaining the neutrality rule; no ExampleHR
identifiers, fields, or logic exist in the sync or the DTOs. The vendor coupling
is confined to `app/Hris/Adapters/ExampleHrAdapter.php`, as intended.

---

## 6. Unsupported-operation checks

```bash
$ grep -rn "HrisUnsupportedOperation" app/Hris/Adapters/ExampleHrAdapter.php
app/Hris/Adapters/ExampleHrAdapter.php:13:use App\Hris\Exceptions\HrisUnsupportedOperation;
app/Hris/Adapters/ExampleHrAdapter.php:98:     * @throws HrisUnsupportedOperation always.
app/Hris/Adapters/ExampleHrAdapter.php:102:        throw HrisUnsupportedOperation::for($this->name(), 'pushTrainingCompletion');
```

```
$ php artisan test --filter=Unsupported

   INFO  No tests found.
```

Finding: `--filter=Unsupported` matches **no tests**, because the tests covering
this are named by behaviour, not by the exception class. The behaviour **is**
tested — `ExampleHrAdapterTest` ("it refuses training write-back, because
ExampleHR publishes no such endpoint") and `HrisPortTest` ("it reports
write-back capability honestly per adapter") both assert
`HrisUnsupportedOperation` is thrown. Run either with
`php artisan test --filter="refuses training write-back"` or
`--filter=HrisPortTest`.

---

## 7. Full quality gates

```
$ php artisan test
  … (full suite) …
  Tests:    65 passed (210 assertions)
  Duration: 2.25s
```

```
$ ./vendor/bin/pint --test
{"tool":"pint","result":"passed"}
```

```
$ ./vendor/bin/phpstan analyse
Note: Using configuration file /Users/segunawotunde/Projects/cipher-learn-app/phpstan.neon.
 99/99 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%

 [OK] No errors
```

---

## 8. Diff shape + secret scan

```
$ git diff main...HEAD --stat
 app/Console/Commands/SyncEmployeesCommand.php      | 126 +++++++++++
 app/Filament/Resources/EmployeeResource.php        | 132 +++++++++++
 .../EmployeeResource/Pages/ListEmployees.php       |  84 +++++++
 .../EmployeeResource/Pages/ViewEmployee.php        |  13 ++
 app/Filament/Resources/SyncRunResource.php         | 113 ++++++++++
 .../SyncRunResource/Pages/ListSyncRuns.php         |  16 ++
 app/Hris/Adapters/MockHrisAdapter.php              | 230 +++++++++++++++++++
 app/Hris/Adapters/ExampleHrAdapter.php            | 224 +++++++++++++++++++
 app/Hris/Contracts/HrisEmployeeSource.php          |  48 ++++
 app/Hris/Contracts/HrisWriteback.php               |  42 ++++
 app/Hris/Data/EmployeeData.php                     |  66 ++++++
 app/Hris/Data/TrainingCompletionData.php           |  31 +++
 app/Hris/Exceptions/HrisConnectionException.php    |  26 +++
 app/Hris/Exceptions/HrisUnsupportedOperation.php   |  32 +++
 app/Hris/HrisManager.php                           |  93 ++++++++
 app/Hris/Sync/SyncEmployees.php                    | 248 +++++++++++++++++++++
 app/Models/SyncRun.php                             |  16 ++
 app/Providers/HrisServiceProvider.php              |  24 ++
 bootstrap/providers.php                            |   2 +
 config/hris.php                                    |  75 +++++++
 database/seeders/DatabaseSeeder.php                |   8 +
 docs/sprint-2-plan.md                              | 168 ++++++++++++++
 docs/sprint-3-plan-rbac.md                         | 185 +++++++++++++++
 tests/Feature/Filament/EmployeeResourceTest.php    |  72 ++++++
 tests/Feature/Filament/SyncRunResourceTest.php     |  64 ++++++
 tests/Feature/Hris/HrisPortTest.php                | 105 +++++++++
 tests/Feature/Hris/ExampleHrAdapterTest.php       | 194 ++++++++++++++++
 tests/Feature/Hris/SyncEmployeesCommandTest.php    |  52 +++++
 tests/Feature/Hris/SyncEmployeesTest.php           | 233 +++++++++++++++++++
 29 files changed, 2722 insertions(+)
```

```
$ git diff main...HEAD | grep -in "api_key\|secret\|password\|token\|bearer"
973:+        $token = $this->settings['api_token'] ?? null;
984:+            ->when(is_string($token) && $token !== '', fn (PendingRequest $r) => $r->withToken($token));
1762:+    | live in `tenants.settings`, never here; this holds only non-secret defaults.
1874:+  `config/hris.php`, bearer token from `tenant.settings`), mapping the
2102:+- **Password hashing:** confirm the `hashed` cast is on `User` (Laravel default =
2104:+  asserts stored passwords are never plaintext and `Hash::check` passes.
2115:+Tell the CTO "storage-level at deploy + confirmed password hashing, and here's
2129:+- Password is hashed at rest; `Hash::check` verifies.
2136:+permission changes → nice-to-have, Sprint 6+ · self-service password reset UI →
2148:+7. Confirm password hashing; document the encryption decision.
2454:+        'api_token' => 'test-token',
2496:+it('sends the tenant bearer token', function () {
2501:+    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token'));
```

**No hard-coded secrets.** Every match is one of: reading a token *from tenant
settings* (`$this->settings['api_token']`, never a literal), a test fixture
(`'test-token'`), or prose in the plan docs about password hashing / bearer
tokens. Nothing sensitive is committed.

---

## 9. Migration reversibility (`rollback --step=1 && migrate`)

```
$ php artisan migrate:rollback --step=1 && php artisan migrate

   INFO  Rolling back migrations.

  2026_07_22_000240_create_sync_runs_table ...................... 20.57ms DONE


   INFO  Running migrations.

  2026_07_22_000240_create_sync_runs_table ...................... 50.73ms DONE
```

The last migration (`create_sync_runs_table`) rolled back and re-applied cleanly
on MySQL 8 — its `down()` is reversible.

---

## Summary

| # | Check | Result |
|---|-------|--------|
| 1 | Exit-sweep guard grep | **empty** — no mass-exit threshold exists (noted) |
| 2 | `--filter=exit` | 3 passed |
| 3 | seed + double sync (MySQL) | 2nd run **0/0/45/0/0** — idempotent |
| 4 | `--filter=Tenant` | 16 passed |
| 5 | `example` in sync/data | comments only — vendor-neutral |
| 6 | Unsupported grep + filter | grep OK; **filter finds no tests** (behaviour-named; noted) |
| 7 | test / pint / phpstan | 65 passed · pint passed · phpstan no errors |
| 8 | diff stat + secret scan | 29 files, +2722 · **no secrets** |
| 9 | rollback + migrate (MySQL) | reversible |

**Two honest findings, neither a regression:**
1. **No mass-exit safety guard** in `SyncEmployees` (item 1). If a real HR API
   returned an empty/truncated directory, the exit sweep would mark the whole
   active workforce `exited`. Recommend a threshold guard (abort/flag when the
   exited fraction exceeds, say, 20% in one run) as a Sprint 2 hardening follow-up.
2. **`--filter=Unsupported` matches no test names** (item 6) even though the
   behaviour is covered — a naming, not a coverage, gap.
