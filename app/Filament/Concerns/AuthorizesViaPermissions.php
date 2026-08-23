<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Gates a Filament resource behind two spatie permissions: one to see it, one to
 * change it. The resource declares the two permission names; this maps them onto
 * Filament's authorization hooks (which also drive navigation visibility).
 *
 * Permission checks run against the current tenant's team (TenancyTeamResolver),
 * and the Gate::before platform-admin bypass applies automatically — so a
 * platform super-admin passes every check here without holding the permission.
 *
 * Read-only resources should NOT use this trait: they set their own
 * canCreate/canEdit/canDelete to false and only need a view gate.
 */
trait AuthorizesViaPermissions
{
    /**
     * Resources using this trait must define:
     *   protected static string $viewPermission;
     *   protected static string $managePermission;
     */
    public static function canViewAny(): bool
    {
        return static::userCan(static::$viewPermission);
    }

    public static function canView(Model $record): bool
    {
        return static::userCan(static::$viewPermission);
    }

    public static function canCreate(): bool
    {
        return static::userCan(static::$managePermission);
    }

    public static function canEdit(Model $record): bool
    {
        return static::userCan(static::$managePermission);
    }

    public static function canDelete(Model $record): bool
    {
        return static::userCan(static::$managePermission);
    }

    public static function canDeleteAny(): bool
    {
        return static::userCan(static::$managePermission);
    }

    protected static function userCan(string $permission): bool
    {
        return auth()->user()?->can($permission) ?? false;
    }
}
