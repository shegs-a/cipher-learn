<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Congratulates a learner on completing a course and points them at their new
 * certificate — the closing note of the journey. Scalar payload only (queued).
 */
class CourseCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $courseTitle,
        public readonly ?string $certificateId,
        public readonly ?string $certificateSerial,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Completed: {$this->courseTitle}")
            ->line("Congratulations — you've completed **{$this->courseTitle}**.");

        if ($this->certificateId !== null) {
            $mail->line('Your certificate is ready.')
                ->action('View your certificate', route('portal.certificate', $this->certificateId));
        }

        return $mail->line('Well done!');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'course_completed',
            'title' => 'Course completed',
            'body' => $this->courseTitle,
            'serial' => $this->certificateSerial,
            'url' => $this->certificateId !== null ? route('portal.certificate', $this->certificateId) : route('portal'),
        ];
    }
}
