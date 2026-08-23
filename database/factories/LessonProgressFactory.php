<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LessonProgress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LessonProgress>
 */
class LessonProgressFactory extends Factory
{
    protected $model = LessonProgress::class;

    public function definition(): array
    {
        return [
            'status' => 'not_started',
            'seconds_spent' => 0,
            'completed_at' => null,
        ];
    }
}
