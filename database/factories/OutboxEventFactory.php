<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OutboxEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutboxEvent>
 */
class OutboxEventFactory extends Factory
{
    protected $model = OutboxEvent::class;

    public function definition(): array
    {
        return [
            'type' => 'training.completion',
            'payload' => ['example' => true],
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
        ];
    }
}
