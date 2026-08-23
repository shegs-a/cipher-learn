<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an approver (a learner's manager) that one of their people has requested
 * access to a course, so they can approve or reject it. Scalar payload only
 * (queued; see {@see CourseAssignedNotification}).
 */
class AccessRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $learnerName,
        public readonly string $courseTitle,
        public readonly string $note,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Access request: {$this->courseTitle}")
            ->line("**{$this->learnerName}** has requested access to **{$this->courseTitle}**.")
            ->line("Their note: {$this->note}")
            ->action('Review requests', route('filament.admin.resources.enrollments.index'))
            ->line('You can approve or reject it from the Enrolments screen.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'access_requested',
            'title' => 'Access request',
            'body' => "{$this->learnerName} requested {$this->courseTitle}",
            'url' => route('filament.admin.resources.enrollments.index'),
        ];
    }
}
