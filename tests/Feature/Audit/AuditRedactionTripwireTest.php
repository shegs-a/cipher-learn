<?php

declare(strict_types=1);

use App\Models\Certificate;
use App\Models\Course;
use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\PathEnrollment;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\Auditor;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * A tripwire, not a behaviour test.
 *
 * The Auditor redacts a DENYLIST of sensitive attribute names ({@see Auditor}),
 * which is fail-open: a *new* sensitive column (salary, national_id, a token…)
 * added to an audited model would land in `old_values`/`new_values` in plaintext,
 * permanently, until someone remembered to add it to the list.
 *
 * This test walks the real columns of every audited model, flags any whose name
 * looks sensitive, pushes a marker value for each through the Auditor, and asserts
 * every one comes back `[redacted]`. So the day a sensitive-looking column is
 * added, THIS test fails and names it — a deliberate decision is forced (add it to
 * Auditor::REDACT, or, if it is genuinely safe, add it to the acknowledged list
 * below) before the trail can ever store it in the clear.
 *
 * It is a stopgap until the planned V2 move to an allowlist (opt-in per-model
 * audited attributes), which is fail-closed by construction.
 */

/** Every model carrying the Auditable trait — the models whose writes are recorded. */
const AUDITED_MODELS = [
    Enrollment::class,
    Certificate::class,
    PathEnrollment::class,
    Course::class,
    Lesson::class,
    Quiz::class,
    Question::class,
    LearningPath::class,
    Employee::class,
    User::class,
];

/**
 * Substrings that mark a column as too sensitive to sit in an audit log in the
 * clear: auth secrets, financial data, and government identifiers. Ordinary
 * contact PII (name, email) is deliberately NOT here — it is the legitimate
 * content of the trail (a failed login records the attempted email on purpose).
 */
const SENSITIVE_PATTERNS = [
    // auth secrets
    'password', 'passwd', 'secret', 'token', 'credential',
    'api_key', 'apikey', 'access_key', 'private_key',
    // financial
    'salary', 'compensation', 'payroll', 'bank', 'iban',
    'account_number', 'card_number', 'cvv', 'sort_code', 'routing',
    // government / identity documents
    'ssn', 'national_id', 'passport', 'tax_id',
    // other high-sensitivity personal data
    'date_of_birth',
];

/**
 * Columns that match a sensitive pattern but are genuinely safe to record — an
 * explicit, reviewed exception. Empty today; adding here is a conscious sign-off.
 *
 * @var list<string>
 */
const ACKNOWLEDGED_SAFE = [];

it('redacts every sensitive-looking column on every audited model', function () {
    // Collect the distinct columns, across all audited tables, whose names look
    // sensitive by the patterns above (minus any explicitly acknowledged-safe).
    $flagged = [];
    foreach (AUDITED_MODELS as $model) {
        $table = (new $model)->getTable();
        foreach (Schema::getColumnListing($table) as $column) {
            $lower = strtolower($column);
            foreach (SENSITIVE_PATTERNS as $pattern) {
                if (str_contains($lower, $pattern) && ! in_array($column, ACKNOWLEDGED_SAFE, true)) {
                    $flagged[$column] = true;
                    break;
                }
            }
        }
    }
    $flagged = array_keys($flagged);

    // Sanity: the scan must actually find something known-sensitive, otherwise a
    // broken scan (e.g. an API change to getColumnListing) would pass vacuously.
    expect($flagged)->toContain('password');

    // Push a marker for each flagged column through the real writer, then check
    // what was actually stored.
    $tenant = Tenant::factory()->create();
    $payload = array_fill_keys($flagged, 'SENSITIVE-MUST-NOT-BE-STORED');

    $entry = app(Tenancy::class)->runFor(
        $tenant,
        fn () => app(Auditor::class)->log('tripwire.check', newValues: $payload),
    );

    // Any flagged column whose value survived un-redacted is a leak.
    $leaked = array_values(array_filter(
        $flagged,
        fn (string $column): bool => ($entry->new_values[$column] ?? null) !== '[redacted]',
    ));

    expect($leaked)->toBe([], 'These columns look sensitive but are NOT redacted by the audit trail. '
        .'Add each to Auditor::REDACT (or, if genuinely safe, to ACKNOWLEDGED_SAFE in this test): '
        .implode(', ', $leaked));
});
