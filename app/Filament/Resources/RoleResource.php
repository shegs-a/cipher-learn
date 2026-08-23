<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\AuthorizesViaPermissions;
use App\Filament\Resources\RoleResource\Pages;
use App\Support\Tenancy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * View and manage the roles within the current tenant, and the permissions each
 * grants.
 *
 * Viewing is gated by roles.view; creating/editing/deleting a role (i.e.
 * defining what a role can do) is roles.manage — Tenant Admin only — so a
 * delegated user-manager with only roles.assign can hand out existing roles but
 * never invent one that escalates privilege.
 *
 * Tenant-scoped: only this tenant's roles are listed (spatie stamps the team id
 * on create via TenancyTeamResolver). Permissions are synced in the page hooks.
 */
class RoleResource extends Resource
{
    use AuthorizesViaPermissions;

    protected static ?string $model = Role::class;

    protected static string $viewPermission = 'roles.view';

    protected static string $managePermission = 'roles.manage';

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 2;

    /**
     * Only this tenant's roles.
     *
     * @return Builder<Role>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', app(Tenancy::class)->id());
    }

    /**
     * Refuse to delete a built-in role: removing Tenant Admin (or another seeded
     * role) would strand a tenant. Custom roles remain deletable.
     */
    public static function canDelete(Model $record): bool
    {
        /** @var Role $record */
        if (in_array($record->name, array_keys(RolesAndPermissionsSeeder::ROLES), true)) {
            return false;
        }

        return auth()->user()?->can('roles.manage') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                // Unique per tenant (the DB enforces (tenant_id, name, guard)).
                ->helperText('The role name, unique within your organisation.'),

            CheckboxList::make('permissions')
                ->options(fn (): array => Permission::query()
                    ->orderBy('name')
                    ->pluck('name', 'name')
                    ->all())
                ->columns(2)
                ->searchable()
                ->bulkToggleable()
                ->dehydrated(false) // synced via syncPermissions() in the hooks
                ->afterStateHydrated(function (CheckboxList $component, ?Role $record): void {
                    $component->state($record?->permissions->pluck('name')->all() ?? []);
                })
                ->helperText('What this role is allowed to do.'),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('semibold'),
                TextColumn::make('permissions_count')
                    ->counts('permissions')
                    ->label('Permissions')
                    ->alignCenter(),
                TextColumn::make('users_count')
                    ->counts('users')
                    ->label('Users')
                    ->alignCenter(),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
