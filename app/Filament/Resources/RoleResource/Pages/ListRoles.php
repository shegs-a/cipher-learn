<?php

declare(strict_types=1);

namespace App\Filament\Resources\RoleResource\Pages;

use App\Actions\Rbac\ElevateEmployeeToRole;
use App\Filament\Resources\RoleResource;
use App\Models\Employee;
use App\Support\Tenancy;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Spatie\Permission\Models\Role;
use Throwable;

class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->createLmsAdminAction(),
            CreateAction::make()->label('New role'),
        ];
    }

    /**
     * "Create LMS admin": elevate an existing employee by picking a role and the
     * employee to grant it to. The employee dropdown is populated from the
     * employees table; the person stays an Employee, and a login is provisioned
     * behind the scenes (see ElevateEmployeeToRole). Gated by roles.assign.
     */
    private function createLmsAdminAction(): Action
    {
        return Action::make('createLmsAdmin')
            ->label('Create LMS admin')
            ->icon('heroicon-o-user-plus')
            ->visible(fn (): bool => auth()->user()?->can('roles.assign') ?? false)
            ->modalHeading('Create LMS admin')
            ->modalDescription('Elevate an employee by granting them a role. A login is provisioned for them automatically.')
            ->form([
                Select::make('employee_id')
                    ->label('Employee')
                    ->options(fn (): array => Employee::query()
                        ->orderBy('first_name')
                        ->get()
                        ->mapWithKeys(fn (Employee $e): array => [
                            $e->id => $e->full_name.($e->email ? ' · '.$e->email : ' · (no email)'),
                        ])
                        ->all())
                    ->searchable()
                    ->required()
                    ->live()
                    ->helperText('Populated from the employee directory.'),

                Select::make('role')
                    ->label('Role')
                    ->options(fn (): array => Role::query()
                        ->where('tenant_id', app(Tenancy::class)->id())
                        ->where('name', '!=', 'Learner')
                        ->orderBy('name')
                        ->pluck('name', 'name')
                        ->all())
                    ->required(),

                TextInput::make('password')
                    ->label('Initial password')
                    ->password()
                    ->revealable()
                    ->maxLength(255)
                    ->helperText('Sets the sign-in password for this person.')
                    // Only relevant when the chosen employee has no login yet.
                    ->visible(fn (Get $get): bool => $this->employeeNeedsLogin($get('employee_id')))
                    ->required(fn (Get $get): bool => $this->employeeNeedsLogin($get('employee_id'))),
            ])
            ->action(function (array $data): void {
                try {
                    /** @var Employee $employee */
                    $employee = Employee::findOrFail($data['employee_id']);

                    $user = app(ElevateEmployeeToRole::class)->handle(
                        $employee,
                        $data['role'],
                        $data['password'] ?? null,
                    );

                    Notification::make()
                        ->title('LMS admin created')
                        ->body("{$employee->full_name} now holds the {$data['role']} role and can sign in as {$user->email}.")
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Could not elevate employee')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    private function employeeNeedsLogin(mixed $employeeId): bool
    {
        if (blank($employeeId)) {
            return false;
        }

        return Employee::whereKey($employeeId)->value('user_id') === null;
    }
}
