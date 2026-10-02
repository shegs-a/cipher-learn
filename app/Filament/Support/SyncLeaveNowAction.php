<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Hris\Sync\RequestManualLeaveSync;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * The admin "Sync leave now" button — the manual counterpart to the twice-daily
 * scheduled leave sync, for when someone knows leave was just approved. Shared by
 * the Employees and Sync history pages so both behave identically. Visible only to
 * people holding `hris.sync`.
 */
final class SyncLeaveNowAction
{
    public static function make(): Action
    {
        return Action::make('syncLeave')
            ->label('Sync leave now')
            ->icon('heroicon-o-calendar-days')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('hris.sync') ?? false)
            ->requiresConfirmation()
            ->modalHeading('Refresh leave from the HR system')
            ->modalDescription('Leave is refreshed automatically at 06:00 and 18:00 (your organisation’s local time). Use this if leave was approved since then and you need the course-assignment leave check to reflect it now.')
            ->action(function (): void {
                $user = auth()->user();
                $tenant = $user instanceof User ? $user->tenant : null;

                if (! $user instanceof User || $tenant === null) {
                    Notification::make()->title('No organisation in context')->danger()->send();

                    return;
                }

                $reason = app(RequestManualLeaveSync::class)->handle($tenant, $user);

                if ($reason !== null) {
                    Notification::make()->title('Leave sync not started')->body($reason)->warning()->send();

                    return;
                }

                Notification::make()
                    ->title('Leave sync started')
                    ->body('It runs in the background. Follow the result under People › Sync history.')
                    ->success()
                    ->send();
            });
    }
}
