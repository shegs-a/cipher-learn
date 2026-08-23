<?php

declare(strict_types=1);

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Resources\EmployeeResource;
use App\Hris\Sync\SyncEmployees;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Throwable;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Re-run the authoritative sync on demand. Confirmation is required
            // because for a real tenant this hits the HR system's API, and the
            // exit sweep can flip people to `exited` — not a click to fire by
            // accident. The stats come straight from the SyncRun so the operator
            // sees exactly what changed.
            Action::make('sync')
                ->label('Sync now')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalHeading('Sync employees from the HR system')
                ->modalDescription('Pulls the current directory: adds new joiners, updates changed records, and marks anyone no longer listed as exited.')
                ->action(function (): void {
                    $tenant = $this->currentTenant();

                    if ($tenant === null) {
                        Notification::make()
                            ->title('No tenant in context')
                            ->body('Could not determine which organisation to sync.')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        $run = app(SyncEmployees::class)->forTenant($tenant);
                        $stats = $run->stats ?? [];

                        Notification::make()
                            ->title('Employee sync complete')
                            ->body(sprintf(
                                '%d added, %d updated, %d unchanged, %d exited, %d errors.',
                                $stats['created'] ?? 0,
                                $stats['updated'] ?? 0,
                                $stats['unchanged'] ?? 0,
                                $stats['exited'] ?? 0,
                                $stats['errors'] ?? 0,
                            ))
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        // The failure is already recorded on the SyncRun; surface
                        // it to the operator rather than a white error screen.
                        Notification::make()
                            ->title('Employee sync failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    /**
     * The tenant this panel is operating in, taken from the signed-in user.
     */
    private function currentTenant(): ?Tenant
    {
        $user = auth()->user();

        return $user?->tenant()->first();
    }
}
