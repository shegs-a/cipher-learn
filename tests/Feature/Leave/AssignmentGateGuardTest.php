<?php

declare(strict_types=1);

/**
 * A tripwire, not a behaviour test — in the same spirit as the audit-redaction one.
 *
 * The leave policy lives in AssignCourse / AssignLearningPath. It only protects
 * anyone if EVERY assignment goes through them. This walks app/ and fails if some
 * other class writes an enrolment or a path membership directly, which would be a
 * back door around the policy (and around notifications and audit). A future
 * assignment workflow that needs to write enrolments should call the shared
 * services instead — which is the whole point of enforcing the policy there.
 */
it('creates enrolments and path memberships only inside the shared assignment services', function () {
    $allowed = [
        'Actions/Assignment/AssignCourse.php',
        'Actions/Learning/AssignLearningPath.php',
    ];

    $write = '/(Path)?Enrollment::(create|firstOrCreate|updateOrCreate|forceCreate|insert|upsert)\b|new (Path)?Enrollment\b|->enrollments\(\)->(create|firstOrCreate|updateOrCreate)|->pathEnrollments\(\)->(create|firstOrCreate|updateOrCreate)/';

    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(app_path()) + 1));

        if (in_array($relative, $allowed, true)) {
            continue;
        }

        if (preg_match($write, (string) file_get_contents($file->getPathname()))) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([], 'These files write enrolments directly, bypassing the leave policy: '.implode(', ', $offenders));
});
