<?php

declare(strict_types=1);

namespace App\Enums;

enum QuestionType: string
{
    case Single = 'single';     // one correct option
    case Multiple = 'multiple'; // one or more correct options
    case Boolean = 'boolean';   // true / false

    public function label(): string
    {
        return match ($this) {
            self::Single => 'Single choice',
            self::Multiple => 'Multiple choice',
            self::Boolean => 'True / false',
        };
    }
}
