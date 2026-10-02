<?php

declare(strict_types=1);

namespace App\Enums;

/** What happened to one employee in one assignment attempt. */
enum AssignmentOutcome: string
{
    /** A new assignment was created (or a pending request became one). */
    case Assigned = 'assigned';

    /** Policy refused it — the employee is on leave and the tenant disallows that. */
    case Blocked = 'blocked';

    /** Nothing new to do: already assigned, already finished, or otherwise not applicable. */
    case Skipped = 'skipped';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
