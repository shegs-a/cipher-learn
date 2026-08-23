<?php

declare(strict_types=1);

namespace App\Enums;

enum EnrollmentStatus: string
{
    // A learner asked to take a course they were not assigned. Awaiting a
    // manager/admin decision; approval flips it to Assigned, rejection to
    // Cancelled. Not yet a real assignment — it holds no learning obligation.
    case Requested = 'requested';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    // Learner left the org before completing — closed without penalty.
    case Waived = 'waived';
    // Explicitly cancelled by an admin. Records are never deleted; this is the
    // "cancellation is a status change" rule.
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In progress',
            default => ucfirst($this->value),
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Assigned, self::InProgress], true);
    }
}
