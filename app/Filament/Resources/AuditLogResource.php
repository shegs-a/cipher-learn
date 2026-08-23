<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Models\AuditLog;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The read-only window onto the tamper-evident audit trail.
 *
 * Deliberately view-only — nothing here (or anywhere in the app) can edit or
 * delete an audit row; the model itself blocks it. Gated by `audit.view`, which
 * only the Tenant Admin holds: the trail records who did what to whom, so it sits
 * behind the tightest gate in the tenant. The list is tenant-scoped by the
 * model's global scope, so an admin sees only their own tenant's chain.
 *
 * Each row shows a live integrity check ({@see AuditLog::hasValidHash()}): a green
 * tick means the stored hash still matches a recompute of the row. A red mark
 * would mean that row was altered outside the append-only path — the same signal
 * the `audit:verify` command raises, surfaced per-row in the UI.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-finger-print';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Audit trail';

    protected static ?string $modelLabel = 'audit entry';

    protected static ?string $pluralModelLabel = 'Audit trail';

    protected static ?string $slug = 'audit-trail';

    protected static ?int $navigationSort = 9;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('audit.view') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('audit.view') ?? false;
    }

    /** The trail is append-only and system-written — never authored by hand. */
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
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->since()
                    ->sortable(),
                TextColumn::make('sequence')
                    ->label('#')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('event')
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        str_starts_with($state, 'auth.login_failed') => 'danger',
                        str_starts_with($state, 'auth.') => 'info',
                        str_starts_with($state, 'rbac.') => 'warning',
                        str_ends_with($state, '.deleted') => 'danger',
                        str_ends_with($state, '.created') => 'success',
                        default => 'gray',
                    })
                    ->searchable()
                    ->sortable(),
                TextColumn::make('auditable_type')
                    ->label('Subject')
                    // Show "Course #01J…" — the model, not its fully-qualified class.
                    ->formatStateUsing(fn (?string $state, AuditLog $record): string => $state === null
                        ? '—'
                        : class_basename($state).' #'.$record->auditable_id)
                    ->toggleable(),
                TextColumn::make('actor_label')
                    ->label('Actor')
                    ->placeholder('system')
                    ->description(fn (AuditLog $record): ?string => $record->actor_roles === null
                        ? null
                        : implode(', ', $record->actor_roles))
                    ->searchable(),
                IconColumn::make('integrity')
                    ->label('Integrity')
                    ->alignCenter()
                    // Live hash re-check per row: is this entry still what was written?
                    ->state(fn (AuditLog $record): bool => $record->hasValidHash())
                    ->boolean()
                    ->trueIcon('heroicon-o-shield-check')
                    ->falseIcon('heroicon-o-shield-exclamation')
                    ->trueColor('success')
                    ->falseColor('danger'),
            ])
            ->defaultSort('sequence', 'desc')
            ->filters([
                SelectFilter::make('event')
                    ->options(fn (): array => AuditLog::query()
                        ->distinct()
                        ->orderBy('event')
                        ->pluck('event', 'event')
                        ->all()),
                Filter::make('created_at')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->actions([
                ViewAction::make(),
            ])
            // No bulk actions: there is nothing to do to an audit row in bulk (or
            // at all) — it is a permanent record.
            ->bulkActions([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->columns(1)
            ->schema([
                Section::make('Event')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('event')->badge(),
                        TextEntry::make('created_at')->label('When')->dateTime(),
                        TextEntry::make('sequence')->label('Chain #'),
                        TextEntry::make('actor_label')
                            ->label('Actor')
                            ->placeholder('system'),
                        TextEntry::make('actor_roles')
                            ->label('Actor roles')
                            ->badge()
                            ->placeholder('—'),
                        TextEntry::make('auditable_type')
                            ->label('Subject')
                            ->formatStateUsing(fn (?string $state, AuditLog $record): string => $state === null
                                ? '—'
                                : class_basename($state).' #'.$record->auditable_id),
                        IconEntry::make('integrity')
                            ->label('Hash valid')
                            ->state(fn (AuditLog $record): bool => $record->hasValidHash())
                            ->boolean(),
                    ]),
                Section::make('What changed')
                    ->columns(2)
                    ->schema([
                        KeyValueEntry::make('old_values')
                            ->label('Before')
                            ->keyLabel('Field')
                            ->valueLabel('Old value'),
                        KeyValueEntry::make('new_values')
                            ->label('After')
                            ->keyLabel('Field')
                            ->valueLabel('New value'),
                    ]),
                Section::make('Context')
                    ->collapsed()
                    ->schema([
                        KeyValueEntry::make('context')
                            ->label('Request context'),
                    ]),
                Section::make('Chain')
                    ->collapsed()
                    ->columns(1)
                    ->schema([
                        TextEntry::make('previous_hash')
                            ->label('Previous hash')
                            ->placeholder('— (genesis)')
                            ->copyable(),
                        TextEntry::make('hash')
                            ->label('This hash')
                            ->copyable(),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
        ];
    }
}
