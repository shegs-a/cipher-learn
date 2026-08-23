<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Nudges a learner about a course that's past its due date. Sent on a cadence by
 * the `notifications:overdue` command (deduped via `enrollments.reminded_at`).
 * Scalar payload only (queued).
 */
class OverdueReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $courseTitle,
        public readonly string $dueAt,
        public readonly int $daysOverdue,
        public readonly string $enrollmentId,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Overdue: {$this->courseTitle}")
            ->line("**{$this->courseTitle}** was due on **{$this->dueAt}** and is now {$this->daysOverdue} day(s) overdue.")
            ->line('Please complete it as soon as you can.')
            ->action('Continue the course', route('portal.course', $this->enrollmentId));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'overdue_reminder',
            'title' => 'Course overdue',
            'body' => "{$this->courseTitle} — {$this->daysOverdue} day(s) overdue",
            'url' => route('portal.course', $this->enrollmentId),
        ];
    }
}
