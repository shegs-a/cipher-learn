<?php

declare(strict_types=1);

namespace App\Actions\Learning;

use RuntimeException;

/**
 * Thrown when a learner tries to submit a quiz they have no attempts left for.
 *
 * The UI gates this (the submit control disappears once attempts run out), but
 * {@see SubmitQuizAttempt} enforces the ceiling server-side regardless — the
 * attempt count is never trusted from the client. This is the defence-in-depth
 * signal for that guard.
 */
final class QuizAttemptsExhausted extends RuntimeException
{
    public static function forQuiz(int $max): self
    {
        return new self("No attempts remaining (limit: {$max}).");
    }
}
