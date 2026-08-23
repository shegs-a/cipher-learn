<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Support\Audit\Auditor;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Re-sync roles via spatie (team-aware) — but ONLY when the actor holds
     * roles.assign. Otherwise the roles field was hidden and absent from the
     * form state, and syncing would wipe the user's existing roles.
     */
    protected function afterSave(): void
    {
        if (auth()->user()?->can('roles.assign')) {
            /** @var User $user */
            $user = $this->record;
            $before = $user->getRoleNames()->sort()->values()->all();
            /** @var list<string> $roles */
            $roles = $this->data['roles'] ?? [];
            $user->syncRoles($roles);
            $after = $user->getRoleNames()->sort()->values()->all();

            // syncRoles writes spatie's pivot, which no model audit sees. Record
            // the privilege change only when it actually changed the role set.
            if ($before !== $after) {
                app(Auditor::class)->log(
                    'rbac.roles_synced',
                    $user,
                    oldValues: ['roles' => $before],
                    newValues: ['roles' => $after],
                );
            }
        }
    }
}
