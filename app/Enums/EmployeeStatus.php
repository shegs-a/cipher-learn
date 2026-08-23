<?php

declare(strict_types=1);

namespace App\Enums;

enum EmployeeStatus: string
{
    case Active = 'active';
    case Exited = 'exited';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
