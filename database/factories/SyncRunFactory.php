<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncRun>
 */
class SyncRunFactory extends Factory
{
    protected $model = SyncRun::class;

    public function definition(): array
    {
        return [
            'type' => 'employee_sync',
            'status' => 'completed',
            'dry_run' => false,
            'stats' => ['processed' => 0],
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }
}
