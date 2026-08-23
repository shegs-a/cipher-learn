<?php

declare(strict_types=1);

namespace App\Filament\Resources\SyncRunResource\Pages;

use App\Filament\Resources\SyncRunResource;
use Filament\Resources\Pages\ListRecords;

class ListSyncRuns extends ListRecords
{
    protected static string $resource = SyncRunResource::class;

    // No header actions: this is a read-only audit trail. Syncs are triggered
    // from the Employees screen (or the scheduler), and recorded here.
}
