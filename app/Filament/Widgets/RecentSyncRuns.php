<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\SyncRun;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * The most recent background runs (employee syncs today) — the "is the plumbing
 * healthy" panel, reusing the Sprint 2 audit trail. Gated by `employees.view`,
 * matching the Sync history resource.
 */
class RecentSyncRuns extends TableWidget
{
    protected static ?string $heading = 'Recent background runs';

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('employees.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SyncRun::query()->latest('started_at')->limit(8))
            ->paginated(false)
            ->columns([
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'failed' => 'danger',
                        'running' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('stats.created')->label('Created')->alignCenter()->placeholder('—'),
                TextColumn::make('stats.updated')->label('Updated')->alignCenter()->placeholder('—'),
                TextColumn::make('stats.exited')->label('Exited')->alignCenter()->placeholder('—'),
                TextColumn::make('started_at')->since()->sortable(),
            ]);
    }
}
