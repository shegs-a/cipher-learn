<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CourseStatus;
use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Course>
 */
class CourseFactory extends Factory
{
    protected $model = Course::class;

    public function definition(): array
    {
        $title = Str::headline(fake()->unique()->words(3, true));

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1, 99999),
            'summary' => fake()->sentence(12),
            'tags' => fake()->randomElements(['collections', 'compliance', 'sales', 'leadership', 'safety', 'finance', 'communication'], 2),
            'pass_mark' => 70,
            'max_attempts' => 3,
            'recert_months' => fake()->randomElement([null, 12, 24]),
            'estimated_minutes' => fake()->numberBetween(20, 90),
            'status' => CourseStatus::Published,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => CourseStatus::Draft]);
    }
}
