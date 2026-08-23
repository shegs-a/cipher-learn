<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a learner a course has been assigned to them — the notification that ends
 * the "silent loop". Also sent when an access request is approved (a request
 * flipping to `assigned` is, to the learner, just "you now have this course").
 *
 * Carries **scalar data, not an Enrollment model**: notifications are queued and
 * the worker runs outside any tenant context, so a serialized tenant-scoped model
 * would fail to re-fetch. Everything the mail/database payload needs is passed in.
 */
class CourseAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $courseTitle,
        public readonly string $rationale,
        public readonly ?string $dueAt,
        public readonly string $enrollmentId,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("You've been assigned: {$this->courseTitle}")
            ->line("You've been assigned **{$this->courseTitle}**.")
            ->line("**Why you were assigned this:** {$this->rationale}");

        if ($this->dueAt !== null) {
            $mail->line("Please complete it by **{$this->dueAt}**.");
        }

        return $mail
            ->action('Start the course', route('portal.course', $this->enrollmentId))
            ->line('Thank you.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'course_assigned',
            'title' => 'New course assigned',
            'body' => $this->courseTitle,
            'course' => $this->courseTitle,
            'due_at' => $this->dueAt,
            'url' => route('portal.course', $this->enrollmentId),
        ];
    }
}
