<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Quiz;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quiz>
 */
class QuizFactory extends Factory
{
    protected $model = Quiz::class;

    public function definition(): array
    {
        return [
            'title' => 'Assessment',
            'pass_mark' => null, // inherit the course pass mark
            'max_attempts' => null,
        ];
    }
}
