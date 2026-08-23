<?php

declare(strict_types=1);

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\Models\Role;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Hidden for built-in roles by RoleResource::canDelete().
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        /** @var Role $role */
        $role = $this->record;
        /** @var list<string> $permissions */
        $permissions = $this->data['permissions'] ?? [];
        $role->syncPermissions($permissions);
    }
}
