<?php

declare(strict_types=1);

namespace App\Actions\Learning;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Grades a submitted quiz — the one place a `quiz_attempts` row is written and
 * the one place grading happens. **Grading is server-side, always.**
 *
 * The correct answers (`Question.correct_keys`) never leave the server: they are
 * `$hidden` on the model and are only ever read here to score. The stored attempt
 * keeps the learner's own answers and the resulting score/pass — never the keys.
 *
 * Outcomes it drives:
 *   • pass (score ≥ effective pass mark) → the course is completed via
 *     {@see CompleteCourse} (which later issues the certificate + write-back).
 *   • fail with attempts still left → the attempt is recorded; the learner retries.
 *   • fail on the **last** allowed attempt → the enrolment is marked `failed`
 *     (recovery is a fresh assignment, per the Sprint 5 kickoff decision).
 *
 * The attempt ceiling is enforced here, not trusted from the client: a submission
 * beyond {@see Quiz::effectiveMaxAttempts()} throws {@see QuizAttemptsExhausted}.
 *
 * MUST run inside the enrolment's tenant context.
 */
final class SubmitQuizAttempt
{
    public function __construct(private readonly CompleteCourse $completeCourse) {}

    /**
     * @param  array<string, string|list<string>>  $answers  question id → submitted option key(s)
     */
    public function handle(Enrollment $enrollment, Quiz $quiz, array $answers): QuizAttempt
    {
        return DB::transaction(function () use ($enrollment, $quiz, $answers): QuizAttempt {
            $quiz->loadMissing(['questions', 'course']);

            // Enforce the attempt ceiling on the server — never from a hidden field.
            $used = $enrollment->quizAttempts()->where('quiz_id', $quiz->id)->count();
            $max = $quiz->effectiveMaxAttempts();

            if ($used >= $max) {
                throw QuizAttemptsExhausted::forQuiz($max);
            }

            // Grade against the hidden keys. No partial credit: a question earns
            // its points only when the submitted set of keys exactly matches the
            // correct set (covers single, boolean and multiple alike).
            $earned = 0;
            $total = 0;
            $recordedAnswers = [];

            foreach ($quiz->questions as $question) {
                $total += $question->points;

                $submitted = $this->normaliseKeys($answers[$question->id] ?? []);
                $recordedAnswers[$question->id] = $submitted; // learner's answer only

                if ($this->isCorrect($submitted, $question)) {
                    $earned += $question->points;
                }
            }

            $score = $total > 0 ? (int) round($earned / $total * 100) : 0;
            $passed = $score >= $quiz->effectivePassMark();

            $attempt = QuizAttempt::create([
                'enrollment_id' => $enrollment->id,
                'quiz_id' => $quiz->id,
                'employee_id' => $enrollment->employee_id,
                'score' => $score,
                'passed' => $passed,
                'answers' => $recordedAnswers,
                'started_at' => Carbon::now(),
                'submitted_at' => Carbon::now(),
            ]);

            if ($passed) {
                $this->completeCourse->handle($enrollment);
            } elseif ($used + 1 >= $max && $enrollment->status->isOpen()) {
                // Last allowed attempt, still not passed → the course is failed.
                $enrollment->forceFill(['status' => EnrollmentStatus::Failed])->save();
            }

            return $attempt;
        });
    }

    /**
     * Coerce a submitted answer into a clean, sorted list of option keys, so a
     * single string, a boolean's one key, and a multiple's several all compare
     * the same way. Sorting makes the match order-independent.
     *
     * @param  string|list<string>  $answer
     * @return list<string>
     */
    private function normaliseKeys(string|array $answer): array
    {
        $keys = array_values(array_filter(
            array_map('strval', (array) $answer),
            fn (string $k) => $k !== '',
        ));
        sort($keys);

        return $keys;
    }

    /**
     * A question is correct when the learner's key set exactly equals the correct
     * key set. `correct_keys` is read directly off the model (server-side only).
     *
     * @param  list<string>  $submitted  already normalised + sorted
     */
    private function isCorrect(array $submitted, Question $question): bool
    {
        /** @var list<string> $correct */
        $correct = $question->correct_keys;
        sort($correct); // re-indexes to a 0-based list, matching $submitted

        return $submitted === $correct;
    }
}
