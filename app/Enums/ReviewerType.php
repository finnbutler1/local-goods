<?php

namespace App\Enums;

/**
 * Who an invited reviewer is. Every reviewer has the judge role (read-only);
 * the type is for sorting feedback in the inbox. Public is the one shared
 * account linked from the README: anyone with that link uses it, so its
 * feedback is anonymous and more tightly rate-limited.
 */
enum ReviewerType: string
{
    case ContestJudge = 'contest_judge';
    case EmployerHr = 'employer_hr';
    case Merchant = 'merchant';
    case Bid = 'bid';
    case Friend = 'friend';
    case Public = 'public';

    public function label(): string
    {
        return match ($this) {
            self::ContestJudge => 'Contest judge',
            self::EmployerHr => 'Employer HR',
            self::Merchant => 'Merchant',
            self::Bid => 'BID',
            self::Friend => 'Friend',
            self::Public => 'Public judge access',
        };
    }
}
