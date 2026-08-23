<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a learner their access request was declined. (Approval isn't a separate
 * notification — an approved request becomes an assignment, so the learner gets
 * {@see CourseAssignedNotification} instead.) Scalar payload only (queued).
 */
class AccessRequestRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $courseTitle,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Update on your request: {$this->courseTitle}")
            ->line("Your request to access **{$this->courseTitle}** wasn't approved this time.")
            ->line('If you think you need this training, speak to your manager or L&D team.')
            ->action('Browse the catalogue', route('portal.catalogue'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'access_request_rejected',
            'title' => 'Request not approved',
            'body' => $this->courseTitle,
            'url' => route('portal.catalogue'),
        ];
    }
}
