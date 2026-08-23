<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaPermissions;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Support\Tenancy;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

/**
 * "LMS Admins" — the people in this tenant who hold an elevated role.
 *
 * A person stays an {@see Employee} (HR owns that record); this screen is the
 * view of which employees have been elevated and what roles they carry. A login
 * identity ({@see User}) is the auth vehicle behind an elevated employee, linked
 * by employees.user_id — it is not managed as a thing in its own right.
 *
 * You do NOT create admins here — that happens on the Roles screen ("Create LMS
 * admin": pick a role, pick an employee). This screen lists them and lets you
 * adjust roles or deactivate the login.
 *
 * "Elevated" = holds any role other than Learner (Learner is the default, non-
 * privileged role every provisioned learner has).
 */
class UserResource extends Resource
{
    use AuthorizesViaPermissions;

    protected static ?string $model = User::class;

    protected static string $viewPermission = 'users.view';

    protected static string $managePermission = 'users.manage';

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'LMS Admins';

    protected static ?string $modelLabel = 'LMS admin';

    protected static ?string $pluralModelLabel = 'LMS Admins';

    protected static ?string $slug = 'lms-admins';

    protected static ?int $navigationSort = 1;

    /** Admins are elevated via the Roles screen, not created here. */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * This tenant's users who hold an elevated (non-Learner) role.
     *
     * @return Builder<User>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', app(Tenancy::class)->id())
            ->whereHas('roles', fn (Builder $q) => $q->where('name', '!=', 'Learner'));
    }

    /** Used only by the edit page — manage roles and deactivation. */
    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->required()->maxLength(255)
                ->helperText('The login name. The underlying person is the linked employee.'),
            TextInput::make('email')
                ->email()->required()->maxLength(255)
                ->unique(ignoreRecord: true),

            TextInput::make('password')
                ->password()
                ->revealable()
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->helperText('Leave blank to keep the current password.')
                ->maxLength(255),

            Toggle::make('is_active')
                ->default(true)
                ->helperText('Inactive admins cannot sign in.'),

            Select::make('roles')
                ->multiple()
                ->options(fn (): array => Role::query()
                    ->where('tenant_id', app(Tenancy::class)->id())
                    ->where('name', '!=', 'Learner')
                    ->orderBy('name')
                    ->pluck('name', 'name')
                    ->all())
                ->visible(fn (): bool => auth()->user()?->can('roles.assign') ?? false)
                ->dehydrated(false) // synced via syncRoles() in the page hooks
                ->afterStateHydrated(function (Select $component, ?User $record): void {
                    $component->state($record?->roles->pluck('name')->all() ?? []);
                })
                ->helperText('Roles grant permissions within this organisation only.'),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // The login's name/email mirror the person (set when the employee
                // was elevated). Department comes from the linked employee record.
                TextColumn::make('name')->label('Name')->searchable()->sortable()->weight('semibold'),
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('department')
                    ->getStateUsing(fn (User $record): mixed => $record->employee()->value('department'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->placeholder('—'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
