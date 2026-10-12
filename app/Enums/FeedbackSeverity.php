<?php

namespace App\Enums;

enum FeedbackSeverity: string
{
    case NiceToHave = 'nice_to_have';
    case ShouldFix = 'should_fix';
    case Blocker = 'blocker';

    public function label(): string
    {
        return match ($this) {
            self::NiceToHave => 'Nice to have',
            self::ShouldFix => 'Should fix',
            self::Blocker => 'Blocker',
        };
    }
}
