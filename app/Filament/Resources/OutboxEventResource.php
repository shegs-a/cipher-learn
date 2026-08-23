<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OutboxEventResource\Pages;
use App\Models\OutboxEvent;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only view of the write-back outbox — the audit trail for training
 * completions being pushed back to each tenant's HR system.
 *
 * This is where an operator sees the honest end state of the product loop: for a
 * tenant on ExampleHR (no write-back endpoint) completions sit as `unsupported`,
 * built and provable but parked until an endpoint exists; on the mock they read
 * `processed`. Purely a record — nothing here is editable; the worker
 * (`outbox:work`) does the processing.
 */
class OutboxEventResource extends Resource
{
    protected static ?string $model = OutboxEvent::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-on-square-stack';

    protected static ?string $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'Write-back log';

    protected static ?int $navigationSort = 3;

    /** Gated by `enrollments.view` — this is part of the training-records area. */
    public static function canViewAny(): bool
    {
        return auth()->user()?->can('enrollments.view') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('enrollments.view') ?? false;
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
                TextColumn::make('payload.course_title')->label('Course')->searchable(false)->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'processed' => 'success',
                        'failed' => 'danger',
                        'unsupported' => 'warning',
                        'processing' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                TextColumn::make('attempts')->alignCenter()->toggleable(),
                TextColumn::make('last_error')
                    ->label('Detail')
                    ->wrap()
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')->dateTime()->since()->sortable(),
                TextColumn::make('processed_at')->dateTime()->since()->placeholder('—')->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'processed' => 'Processed',
                    'unsupported' => 'Unsupported',
                    'failed' => 'Failed',
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOutboxEvents::route('/'),
        ];
    }
}
