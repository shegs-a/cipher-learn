<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ONE summary for a whole bulk assignment in which some employees were blocked for
 * being on leave — instead of one notification per blocked employee. The full list
 * (with leave dates) lives in the operation result and the audit record; here we
 * show the headline plus the first few names. Scalar payload only (queued).
 */
class BulkAssignmentBlockedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** How many blocked names to show before "and N more". */
    private const NAMES_SHOWN = 10;

    /**
     * @param  list<string>  $blockedNames  every blocked employee's name
     */
    public function __construct(
        /** "course" or "learning path" */
        public readonly string $assignmentType,
        public readonly string $subjectName,
        public readonly int $total,
        public readonly int $assigned,
        public readonly int $blocked,
        public readonly array $blockedNames,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    private function headline(): string
    {
        return ucfirst($this->assignmentType).' assignment: some employees were blocked';
    }

    private function body(): string
    {
        return "{$this->blocked} of {$this->total} employees could not be assigned “{$this->subjectName}” because they are currently on leave.";
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->headline())
            ->line($this->body())
            ->line("{$this->assigned} were assigned as normal.");

        $shown = array_slice($this->blockedNames, 0, self::NAMES_SHOWN);
        $more = count($this->blockedNames) - count($shown);

        $mail->line('Blocked: '.implode(', ', $shown).($more > 0 ? " and {$more} more." : '.'));

        return $mail->line('Your organisation’s policy does not allow assignments to employees on leave. The full list, with leave dates, is in the audit trail.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'bulk_assignment_blocked',
            'title' => $this->headline(),
            'body' => $this->body(),
            'url' => url('/admin/audit-logs'),
            'reason' => 'employee_on_leave',
        ];
    }
}
