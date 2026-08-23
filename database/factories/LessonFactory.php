<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lesson>
 */
class LessonFactory extends Factory
{
    protected $model = Lesson::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'content' => fake()->paragraphs(3, true),
            'position' => fake()->numberBetween(1, 10),
            // 0.3–3 MB per module — realistic for text-first content with a
            // diagram or two, and small enough to respect metered data.
            'file_size_bytes' => fake()->numberBetween(300_000, 3_000_000),
            'estimated_minutes' => fake()->numberBetween(5, 20),
        ];
    }
}
