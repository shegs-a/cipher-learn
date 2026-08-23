# Sprint 2 — Exit-Sweep Guard Verification (raw results)

Branch `sprint-2-hris` @ `e76b025`. Run 2026-07-23.

## Script run

```bash
# 1
php artisan test --filter=SyncEmployees
# 2  (re-run of the filtered suite to confirm the idempotency test still passes,
#     then a live seed + double sync on MySQL)
php artisan test --filter=SyncEmployees
php artisan migrate:fresh --seed
DB_HOST=127.0.0.1 php artisan hris:sync-employees --tenant=demo    # run twice
# 3
php artisan test
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
```

## Environment note

- `php artisan test`, `pint`, and `phpstan` ran on local **PHP 8.5.8** against
  sqlite `:memory:` (the phpunit.xml default). CI runs the same suite on PHP 8.3 /
  MySQL 8.
- The DB commands in item 2 target the **MySQL 8** container (`docker compose`
  stack, healthy). `.env` sets `DB_HOST=mysql` (the Docker-internal service name),
  which does not resolve from the host shell, so `migrate:fresh --seed` was run
  with a `DB_HOST=127.0.0.1` prefix to reach the same container. The
  `hris:sync-employees` line already carried that prefix as written. No other
  change. `migrate:fresh` drops and rebuilds the dev database, as that command does.
- Output verbatim. `php artisan test` output in items 1/3a is shown as the tail
  summary; item 1 is shown in full.

---

## 1. `php artisan test --filter=SyncEmployees`

```
   PASS  Tests\Feature\Hris\SyncEmployeesCommandTest
  ✓ it syncs a single tenant by slug                                     0.24s
  ✓ it syncs every tenant with --all                                     0.03s
  ✓ it fails when a named tenant does not exist                          0.01s
  ✓ it succeeds quietly when --all finds no tenants at all               0.01s
  ✓ it rejects being given both --tenant and --all                       0.01s
  ✓ it requires one of --tenant or --all                                 0.01s
  ✓ it exits non-zero and withholds the sweep when the mass-exit guard…  0.01s
  ✓ it applies the sweep when --force is given                           0.01s

   PASS  Tests\Feature\Hris\SyncEmployeesTest
  ✓ it creates employees from the HR directory and records the run       0.01s
  ✓ it is idempotent — a second run against unchanged data writes nothi… 0.01s
  ✓ it resolves reporting lines even when a manager is listed after the… 0.01s
  ✓ it updates a changed field and reports it as updated                 0.01s
  ✓ it marks a person the HR system stopped listing as exited, without…  0.01s
  ✓ it leaves locally-created employees alone during the exit sweep      0.01s
  ✓ it never touches another tenant's employees                          0.01s
  ✓ it counts a record with no external id as an error instead of dupli… 0.01s
  ✓ it withholds the sweep and flags the run when an implausible fracti… 0.01s
  ✓ it applies the withheld sweep when forced                            0.01s
  ✓ it does not trip on an ordinary handful of leavers in a large workf… 0.01s
  ✓ it does not trip on the first sync into an empty tenant              0.01s
  ✓ it does not trip on a tiny workforce below the minimum, even at a h… 0.01s
  ✓ it syncs the real mock adapter end to end                            0.02s

  Tests:    22 passed (67 assertions)
  Duration: 0.59s
```

The five guard cases (trip / force-applies / ordinary-leavers-no-trip /
first-sync-no-trip / tiny-workforce-no-trip) and the two command cases
(non-zero exit on trip / --force applies) are all present and green.

---

## 2. Idempotency re-confirmed, then live seed + double sync (MySQL)

### 2a. Re-run of the filtered suite — idempotency test line + summary

```
$ php artisan test --filter=SyncEmployees
  ✓ it is idempotent — a second run against unchanged data writes nothi… 0.01s
  Tests:    22 passed (67 assertions)
  Duration: 0.60s
```

### 2b. `migrate:fresh --seed` (MySQL 8) — tail

```
$ DB_HOST=127.0.0.1 php artisan migrate:fresh --seed
  2026_07_22_000210_create_certificates_table .................. 124.18ms DONE
  2026_07_22_000220_create_competency_rules_table ............... 57.24ms DONE
  2026_07_22_000230_create_outbox_events_table .................. 33.56ms DONE
  2026_07_22_000240_create_sync_runs_table ...................... 34.51ms DONE


   INFO  Seeding database.
```

### 2c. `hris:sync-employees --tenant=demo` — run twice (MySQL 8)

```
$ DB_HOST=127.0.0.1 php artisan hris:sync-employees --tenant=demo   (RUN 1)
  Syncing Demo Organisation .................................... 116.42ms DONE

+-------------------+---------+---------+---------+-----------+--------+--------+
| Tenant            | Adapter | Created | Updated | Unchanged | Exited | Errors |
+-------------------+---------+---------+---------+-----------+--------+--------+
| Demo Organisation | mock    | 0       | 0       | 45        | 0      | 0      |
+-------------------+---------+---------+---------+-----------+--------+--------+
[exit code: 0]

$ DB_HOST=127.0.0.1 php artisan hris:sync-employees --tenant=demo   (RUN 2)
  Syncing Demo Organisation .................................... 111.31ms DONE

+-------------------+---------+---------+---------+-----------+--------+--------+
| Tenant            | Adapter | Created | Updated | Unchanged | Exited | Errors |
+-------------------+---------+---------+---------+-----------+--------+--------+
| Demo Organisation | mock    | 0       | 0       | 45        | 0      | 0      |
+-------------------+---------+---------+---------+-----------+--------+--------+
[exit code: 0]
```

Both runs after the seed report **0 created, 0 updated, 45 unchanged, 0 exited,
0 errors** and exit 0 — the guard adds no exits on a healthy, complete directory,
and idempotency holds against MySQL 8.

---

## 3. Full quality gates

### 3a. `php artisan test`

```
   PASS  Tests\Feature\WelcomeTest
  ✓ it renders the branded placeholder page                              0.01s

  Tests:    72 passed (237 assertions)
  Duration: 2.41s
```

### 3b. `./vendor/bin/pint --test`

```
$ ./vendor/bin/pint --test
{"tool":"pint","result":"passed"}
```

### 3c. `./vendor/bin/phpstan analyse`

```
$ ./vendor/bin/phpstan analyse
Note: Using configuration file /Users/segunawotunde/Projects/cipher-learn-app/phpstan.neon.
 99/99 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%

 [OK] No errors
```

---

## Summary

| # | Check | Result |
|---|-------|--------|
| 1 | `--filter=SyncEmployees` | **22 passed** (incl. 5 guard + 2 command cases) |
| 2a | idempotency test re-run | passes |
| 2b | `migrate:fresh --seed` (MySQL) | clean |
| 2c | double sync (MySQL) | both **0/0/45/0/0**, exit 0 — idempotent |
| 3 | test / pint / phpstan | **72 passed** · pint passed · phpstan no errors |

No failures. The guard behaviour is exercised by the filtered suite (item 1), and
the healthy-directory path shows the guard adds zero exits and stays idempotent
against MySQL 8 (item 2).
