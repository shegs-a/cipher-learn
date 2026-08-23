<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LearningPath;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LearningPath>
 */
class LearningPathFactory extends Factory
{
    protected $model = LearningPath::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
        ];
    }
}
