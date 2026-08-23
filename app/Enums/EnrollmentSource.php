<?php

declare(strict_types=1);

namespace App\Enums;

enum EnrollmentSource: string
{
    case Manual = 'manual';
    case SelfEnrolled = 'self';
    case Onboarding = 'onboarding';
    case Rule = 'rule';
    case Recommender = 'recommender';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::SelfEnrolled => 'Self-enrolled',
            self::Onboarding => 'Onboarding',
            self::Rule => 'Rule',
            self::Recommender => 'Recommender',
        };
    }
}
