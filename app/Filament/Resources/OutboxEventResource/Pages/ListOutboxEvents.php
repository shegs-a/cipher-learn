<?php

declare(strict_types=1);

namespace App\Filament\Resources\OutboxEventResource\Pages;

use App\Filament\Resources\OutboxEventResource;
use Filament\Resources\Pages\ListRecords;

class ListOutboxEvents extends ListRecords
{
    protected static string $resource = OutboxEventResource::class;

    // No header actions: this is a read-only audit trail. Events are written by
    // course completion and drained by the `outbox:work` worker (or scheduler).
}
