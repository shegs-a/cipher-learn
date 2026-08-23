<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Notifications\OverdueReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Reminds learners about overdue training.
 *
 * Runs with **no tenant bound** (a console sweep), so the BelongsToTenant global
 * scope isn't applied and this sees every tenant's overdue enrolments in one pass;
 * each notification targets a specific User and carries scalar data, so no tenant
 * context is needed to send it.
 *
 * Deduped via `enrollments.reminded_at`: an enrolment is only re-reminded once its
 * last reminder is older than `--days` (default 7), so the scheduler can run daily
 * without emailing the same overdue course every day. Wired to the scheduler in
 * the deploy sprint; runnable by hand now.
 */
class SendOverdueRemindersCommand extends Command
{
    protected $signature = 'notifications:overdue {--days=7 : Minimum days between reminders for the same enrolment}';

    protected $description = 'Send reminder notifications for overdue training (deduped per enrolment)';

    public function handle(): int
    {
        $now = Carbon::now();
        $cutoff = $now->copy()->subDays((int) $this->option('days'));
        $sent = 0;

        Enrollment::query()
            ->whereIn('status', [EnrollmentStatus::Assigned->value, EnrollmentStatus::InProgress->value])
            ->whereNotNull('due_at')
            ->where('due_at', '<', $now)
            ->where(fn ($q) => $q->whereNull('reminded_at')->orWhere('reminded_at', '<', $cutoff))
            ->with(['employee.user', 'course'])
            ->chunkById(200, function ($enrollments) use ($now, &$sent): void {
                foreach ($enrollments as $enrollment) {
                    $user = $enrollment->employee?->user;

                    if ($user === null) {
                        continue; // no login → nothing to notify (still leave reminded_at untouched)
                    }

                    $user->notify(new OverdueReminderNotification(
                        courseTitle: $enrollment->course->title,
                        dueAt: $enrollment->due_at->format('j M Y'),
                        daysOverdue: (int) $enrollment->due_at->diffInDays($now),
                        enrollmentId: $enrollment->id,
                    ));

                    $enrollment->forceFill(['reminded_at' => $now])->save();
                    $sent++;
                }
            });

        $this->components->info("Sent {$sent} overdue reminder(s).");

        return self::SUCCESS;
    }
}
