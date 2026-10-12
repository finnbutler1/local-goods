<?php

namespace App\Enums;

/**
 * Who an invited reviewer is. Every reviewer has the judge role (read-only);
 * the type is only for sorting feedback in the inbox.
 */
enum ReviewerType: string
{
    case ContestJudge = 'contest_judge';
    case EmployerHr = 'employer_hr';
    case Merchant = 'merchant';
    case Bid = 'bid';
    case Friend = 'friend';

    public function label(): string
    {
        return match ($this) {
            self::ContestJudge => 'Contest judge',
            self::EmployerHr => 'Employer HR',
            self::Merchant => 'Merchant',
            self::Bid => 'BID',
            self::Friend => 'Friend',
        };
    }
}
