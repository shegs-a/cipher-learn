<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmployeeLeave;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeLeave>
 */
class EmployeeLeaveFactory extends Factory
{
    protected $model = EmployeeLeave::class;

    public function definition(): array
    {
        return [
            'external_id' => 'LV-'.fake()->unique()->numberBetween(10000, 99999),
            'leave_type' => 'Annual',
            'starts_on' => today()->subDay(),
            'ends_on' => today()->addDays(5),
            'is_current' => null,
            'synced_at' => now(),
        ];
    }

    /** A leave spanning the given dates (Y-m-d). */
    public function between(string $startsOn, string $endsOn): static
    {
        return $this->state(fn () => ['starts_on' => $startsOn, 'ends_on' => $endsOn]);
    }
}
