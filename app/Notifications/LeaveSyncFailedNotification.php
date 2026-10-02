<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells HRIS-sync operators that a leave sync failed. Sent for failures only —
 * successful runs are recorded in the sync history, never emailed (twice a day
 * per tenant would be noise). Scalar payload only (queued).
 */
class LeaveSyncFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $tenantName,
        public readonly string $trigger,
        public readonly string $message,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Leave sync failed — {$this->tenantName}")
            ->line("The {$this->trigger} leave sync for **{$this->tenantName}** failed.")
            ->line($this->message)
            ->line('Until it succeeds, assignment keeps using the last leave data it received, which may be out of date.')
            ->action('View sync history', url('/admin/sync-runs'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'leave_sync_failed',
            'title' => 'Leave sync failed',
            'body' => "The {$this->trigger} leave sync failed: {$this->message}",
            'url' => url('/admin/sync-runs'),
        ];
    }
}
