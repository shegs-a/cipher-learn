<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Hris\Sync\SyncLeave;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * The "Sync leave now" button, run on the queue so an admin's click returns
 * immediately instead of waiting on the HR API. Records a `manual` SyncRun
 * attributed to whoever clicked.
 */
class SyncLeaveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $tenantId,
        public readonly ?string $userId = null,
    ) {}

    public function handle(SyncLeave $sync): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $user = $this->userId !== null ? User::query()->find($this->userId) : null;

        try {
            $sync->forTenant($tenant, 'manual', $user);
        } catch (Throwable) {
            // Already recorded on the SyncRun and alerted by SyncLeave; swallowing
            // here avoids a second, redundant "failed job" on top of it.
        }
    }
}
