# Sprint 3 — Data-protection decisions

This records the deliberate data-protection posture for Sprint 3. The point is to
separate what is *real* from what merely *sounds* reassuring, so the answer we
give a CTO is defensible rather than vague.

## Password hashing — confirmed (not built)

Laravel hashes passwords with **bcrypt** via the `hashed` cast, which is present
on `App\Models\User` (`casts()['password'] => 'hashed'`). We verify this rather
than reinventing it:

- `tests/Feature/Rbac/PasswordHashingTest.php` asserts a stored password is never
  the plaintext, that `Hash::check` verifies it, and that the stored value is a
  bcrypt hash.
- Every place that sets a password goes through the cast or `Hash::make`
  (seeder, the elevate-employee action, the LMS-admin edit form).

We did **not** raise the bcrypt work factor this sprint; the framework default is
appropriate for now and can be bumped in `config/hashing.php` at deploy time.

## Encryption at rest — two different things, don't conflate them

**1. Storage-level encryption** (the database volume and the S3/object bucket
encrypted by the infrastructure). This satisfies most audit checkboxes and is a
**deploy-time setting — Sprint 8**, not application code. Nothing to build here.

**2. Application-level field encryption** (Laravel's `encrypted` cast on
specific columns). **Decision for this sprint: encrypt nothing at the application
layer, on purpose.**

Why:

- The schema **mirrors HRIS data** and owns no genuinely sensitive fields —
  no salary, national ID, bank details, or health data. Names, departments,
  job titles and email are directory-grade data already held in the HR system.
- An `encrypted` column **cannot be indexed or searched**, because ciphertext
  doesn't compare. It therefore cannot go on `email` (the login handle, looked up
  on every sign-in) or `external_id` (the sync join key), or any column we filter
  or match on. Wrapping columns "to be safe" would silently break search and
  tenant scoping for no real gain.
- Password is already one-way hashed (above), which is stronger than reversible
  encryption for that field.

**Revisit when** a field that is both genuinely sensitive *and* not needed for
lookup is added (e.g. a stored performance-appraisal narrative, a document blob).
At that point `encrypted` is the right tool for *that column only*.

## What to tell the CTO

> Passwords are bcrypt-hashed and we have a test proving it. Data at rest is
> encrypted at the storage layer as a deploy setting (Sprint 8). We have
> deliberately *not* field-encrypted application columns, because the schema
> holds directory-grade HR data with nothing that warrants it, and field
> encryption would break the search and tenant-scoping the product depends on.
> We'll add it to a specific column the moment we store something that needs it.

That is a stronger, more honest answer than "everything is encrypted."
