<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\OutboxEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed> $payload
 * @property int $attempts
 */
class OutboxEvent extends Model
{
    /** @use HasFactory<OutboxEventFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
