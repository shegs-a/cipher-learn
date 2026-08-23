<?php

declare(strict_types=1);

namespace App\Livewire\Portal;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * The in-app notifications bell, extracted into its own component so it renders
 * identically inside the shared portal shell on EVERY page. Previously the bell's
 * mark-as-read lived only on the Dashboard component, so it worked nowhere else;
 * as a standalone component the behaviour is uniform across the portal.
 *
 * Opening the bell marks the learner's unread notifications as read, mirroring the
 * original behaviour. Email is the other delivery channel; this is the in-app one.
 */
class NotificationsBell extends Component
{
    /** Mark all of the learner's in-app notifications as read (called on open). */
    public function markRead(): void
    {
        auth()->user()?->unreadNotifications->markAsRead();
    }

    public function render(): View
    {
        $user = auth()->user();

        /** @var Collection<int, object> $notifications */
        $notifications = $user?->notifications()->latest()->limit(8)->get() ?? new Collection;

        return view('livewire.portal.notifications-bell', [
            'notifications' => $notifications,
            'unread' => $user?->unreadNotifications()->count() ?? 0,
        ]);
    }
}
