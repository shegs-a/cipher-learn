<?php

declare(strict_types=1);

namespace App\Enums;

/** Why an assignment was BLOCKED by policy. The value is the stable, machine-readable code. */
enum AssignmentBlockReason: string
{
    case EmployeeOnLeave = 'employee_on_leave';

    public function label(): string
    {
        return match ($this) {
            self::EmployeeOnLeave => 'On leave',
        };
    }
}
