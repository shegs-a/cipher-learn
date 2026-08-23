<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\QuizAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuizAttempt>
 */
class QuizAttemptFactory extends Factory
{
    protected $model = QuizAttempt::class;

    public function definition(): array
    {
        $score = fake()->numberBetween(0, 100);

        return [
            'score' => $score,
            'passed' => $score >= 70,
            'answers' => [],
            'started_at' => now()->subMinutes(10),
            'submitted_at' => now(),
        ];
    }
}
