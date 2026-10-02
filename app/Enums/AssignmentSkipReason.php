<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why an employee was NOT newly assigned although nothing blocked it. Kept apart
 * from {@see AssignmentBlockReason} on purpose: "already has it" is not a refusal.
 */
enum AssignmentSkipReason: string
{
    /** An open enrolment / path membership already exists. */
    case AlreadyAssigned = 'already_assigned';

    /** A finished enrolment (completed, failed or waived) — history is never overwritten. */
    case TerminalStatus = 'terminal_status';

    /** The employee cannot be assigned to (e.g. has exited the organisation). */
    case InvalidEmployee = 'invalid_employee';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::AlreadyAssigned => 'Already assigned',
            self::TerminalStatus => 'Already finished',
            self::InvalidEmployee => 'Not eligible (inactive)',
            self::Other => 'Other',
        };
    }
}
