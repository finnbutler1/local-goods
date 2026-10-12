<?php

namespace App\Enums;

/** Where a piece of feedback is in the platform admin's triage. */
enum FeedbackStatus: string
{
    case New = 'new';
    case Acknowledged = 'acknowledged';
    case Fixed = 'fixed';
    case WontFix = 'wont_fix';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Acknowledged => 'Acknowledged',
            self::Fixed => 'Fixed',
            self::WontFix => "Won't fix",
        };
    }
}
