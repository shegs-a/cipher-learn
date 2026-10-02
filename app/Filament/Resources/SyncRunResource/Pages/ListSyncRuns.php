<?php

declare(strict_types=1);

namespace App\Filament\Resources\SyncRunResource\Pages;

use App\Filament\Resources\SyncRunResource;
use App\Filament\Support\SyncLeaveNowAction;
use Filament\Resources\Pages\ListRecords;

class ListSyncRuns extends ListRecords
{
    protected static string $resource = SyncRunResource::class;

    // This is a read-only audit trail: syncs are recorded here, not edited. The one
    // action is the manual leave refresh, whose result lands in this very list.
    protected function getHeaderActions(): array
    {
        return [SyncLeaveNowAction::make()];
    }
}
