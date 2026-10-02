<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SyncRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One execution of a background process (employee sync, assignment sweep,
 * recertification), kept as an audit trail of what ran and what it did.
 *
 * The @property hints are load-bearing, not decoration: Larastan does not infer
 * types from casts() or the schema, so without them every read of $stats or
 * $started_at is an error at PHPStan level 6.
 *
 * @property string $type
 * @property string $status
 * @property string $trigger scheduled|manual
 * @property string|null $triggered_by_user_id
 * @property string|null $slot tenant-local "date@HH:MM" for scheduled leave syncs
 * @property bool $dry_run
 * @property array<string, mixed>|null $stats
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 */
class SyncRun extends Model
{
    /** @use HasFactory<SyncRunFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $guarded = [];

    /**
     * Who clicked "Sync now", for a manual run.
     *
     * @return BelongsTo<User, $this>
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'stats' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
