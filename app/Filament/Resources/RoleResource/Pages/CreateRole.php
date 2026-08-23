<?php

declare(strict_types=1);

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use App\Support\Tenancy;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\Models\Role;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * Stamp the guard and the team (tenant) id.
     *
     * spatie stamps the team id only via its static Role::create(); Filament
     * creates the model with `new Role()` + save(), which bypasses that — so we
     * set tenant_id (the team key) here explicitly, or the role would be global.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['guard_name'] = 'web';
        $data['tenant_id'] = app(Tenancy::class)->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Role $role */
        $role = $this->record;
        /** @var list<string> $permissions */
        $permissions = $this->data['permissions'] ?? [];
        $role->syncPermissions($permissions);
    }
}
