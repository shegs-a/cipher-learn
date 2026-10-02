<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SyncRunResource\Pages;
use App\Models\SyncRun;
use Carbon\CarbonInterface;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only history of background runs (employee sync today; assignment sweeps
 * and recertification in later sprints).
 *
 * This is the audit trail an operator consults after clicking "Sync now" or when
 * a scheduled sync did something unexpected: what ran, when, how long it took,
 * and exactly what it changed. Purely a record — nothing here is editable.
 */
class SyncRunResource extends Resource
{
    protected static ?string $model = SyncRun::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'Sync history';

    protected static ?int $navigationSort = 2;

    /** Gated by `employees.view` — the sync history is part of the People area. */
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('employees.view') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('employees.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
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
                    })
                    // A failed or skipped run explains itself on hover.
                    ->tooltip(fn (SyncRun $record): ?string => $record->stats['error']
                        ?? $record->stats['reason']
                        ?? (($record->stats['wipe_guard_tripped'] ?? false) ? 'The HR system returned no leave records, so existing leave was left untouched.' : null)),
                // How it started: the scheduled slot (tenant-local) or who clicked the button.
                TextColumn::make('trigger')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'manual' ? 'info' : 'gray')
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                TextColumn::make('slot')->label('Slot')->placeholder('—')->toggleable(),
                TextColumn::make('triggeredBy.name')->label('Triggered by')->placeholder('—')->toggleable(),
                // Individual stat counts read straight out of the JSON column.
                TextColumn::make('stats.fetched')->label('Fetched')->alignCenter()->placeholder('—')->toggleable(),
                TextColumn::make('stats.created')->label('Created')->alignCenter()->placeholder('—'),
                TextColumn::make('stats.updated')->label('Updated')->alignCenter()->placeholder('—'),
                TextColumn::make('stats.removed')->label('Removed')->alignCenter()->placeholder('—')->toggleable(),
                TextColumn::make('stats.unmatched')->label('Unmatched')->alignCenter()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('stats.unchanged')->label('Unchanged')->alignCenter()->placeholder('—')->toggleable(),
                TextColumn::make('stats.exited')->label('Exited')->alignCenter()->placeholder('—'),
                TextColumn::make('stats.errors')
                    ->label('Errors')
                    ->alignCenter()
                    ->placeholder('—')
                    // Draw the eye only when something actually went wrong.
                    ->color(fn ($state): string => (int) $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('started_at')->dateTime()->since()->sortable(),
                TextColumn::make('duration')
                    ->label('Duration')
                    ->state(fn (SyncRun $record): string => $record->finished_at === null
                        ? 'in progress'
                        // Absolute elapsed time (e.g. "3s"), not a relative "3s
                        // before" — hence the ABSOLUTE syntax flag, plus short.
                        : $record->started_at->diffForHumans(
                            $record->finished_at,
                            CarbonInterface::DIFF_ABSOLUTE,
                            short: true,
                        ))
                    ->toggleable(),
            ])
            ->defaultSort('started_at', 'desc')
            ->filters([
                SelectFilter::make('type')->options([
                    'employee_sync' => 'Employee sync',
                    'leave_sync' => 'Leave sync',
                    'assignment_sweep' => 'Assignment sweep',
                    'reconciliation' => 'Reconciliation',
                    'recertification' => 'Recertification',
                ]),
                SelectFilter::make('status')->options([
                    'completed' => 'Completed',
                    'failed' => 'Failed',
                    'running' => 'Running',
                    'skipped' => 'Skipped',
                ]),
                SelectFilter::make('trigger')->options([
                    'scheduled' => 'Scheduled',
                    'manual' => 'Manual',
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSyncRuns::route('/'),
        ];
    }
}
