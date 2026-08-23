<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CompetencyRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompetencyRule>
 */
class CompetencyRuleFactory extends Factory
{
    protected $model = CompetencyRule::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'keywords' => fake()->randomElements(['collections', 'sales', 'safety', 'compliance', 'quality'], 2),
            'attainment_threshold' => 70,
            'due_days' => 30,
            'is_active' => true,
        ];
    }
}
