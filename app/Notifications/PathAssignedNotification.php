<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a learner they've been put on a learning path — **one** notification for
 * the whole curriculum, instead of one per course (the fan-out suppresses the
 * per-course notices). Scalar payload only (queued).
 */
class PathAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $pathName,
        public readonly int $courseCount,
        public readonly string $rationale,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You've been assigned a learning path: {$this->pathName}")
            ->line("You've been assigned the **{$this->pathName}** learning path ({$this->courseCount} ".str('course')->plural($this->courseCount).').')
            ->line("**Why:** {$this->rationale}")
            ->action('View your learning', route('portal'))
            ->line('The courses are now on your My Learning page.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'path_assigned',
            'title' => 'New learning path assigned',
            'body' => $this->pathName,
            'url' => route('portal'),
        ];
    }
}
