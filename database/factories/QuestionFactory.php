<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QuestionType;
use App\Models\Question;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    protected $model = Question::class;

    public function definition(): array
    {
        $options = [
            ['key' => 'a', 'label' => fake()->sentence(3)],
            ['key' => 'b', 'label' => fake()->sentence(3)],
            ['key' => 'c', 'label' => fake()->sentence(3)],
            ['key' => 'd', 'label' => fake()->sentence(3)],
        ];

        return [
            'prompt' => fake()->sentence().'?',
            'type' => QuestionType::Single,
            'options' => $options,
            'correct_keys' => [fake()->randomElement(['a', 'b', 'c', 'd'])],
            'points' => 1,
            'position' => fake()->numberBetween(1, 10),
        ];
    }

    public function boolean(bool $answer = true): static
    {
        return $this->state(fn () => [
            'type' => QuestionType::Boolean,
            'options' => [
                ['key' => 'true', 'label' => 'True'],
                ['key' => 'false', 'label' => 'False'],
            ],
            'correct_keys' => [$answer ? 'true' : 'false'],
        ]);
    }
}
