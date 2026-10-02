<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells whoever initiated an assignment that ONE employee could not be assigned
 * because they are on leave and the tenant's policy disallows it. Goes to the
 * initiating admin / manager only — never to the employee. Scalar payload (queued).
 *
 * For bulk operations see {@see BulkAssignmentBlockedNotification}, which replaces
 * a per-employee flood with a single summary.
 */
class AssignmentBlockedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $employeeName,
        /** "course" or "learning path" */
        public readonly string $assignmentType,
        public readonly string $subjectName,
        /** Pre-formatted, e.g. "28 Sep 2026 – 7 Oct 2026". */
        public readonly string $leavePeriod,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    private function headline(): string
    {
        return ucfirst($this->assignmentType).' assignment blocked';
    }

    private function body(): string
    {
        return "{$this->employeeName} could not be assigned “{$this->subjectName}” because they are currently on leave.";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->headline())
            ->line($this->body())
            ->line("Leave period: {$this->leavePeriod}.")
            ->line('Your organisation’s policy does not allow assignments to employees on leave.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'assignment_blocked',
            'title' => $this->headline(),
            'body' => $this->body().' Leave period: '.$this->leavePeriod.'. Your organisation’s policy does not allow assignments to employees on leave.',
            'url' => url('/admin'),
            'reason' => 'employee_on_leave',
        ];
    }
}
