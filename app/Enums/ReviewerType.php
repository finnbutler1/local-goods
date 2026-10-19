<?php

namespace App\Enums;

/**
 * Who an invited reviewer is. Every reviewer has the judge role (read-only);
 * the type is for sorting feedback in the inbox. Public is the one shared
 * account linked from the README: anyone with that link uses it, so its
 * feedback is anonymous and more tightly rate-limited. A personal contact
 * (ADR 0019) knows Finn: they only vote in the blind adjudication, and their
 * votes are reported on their own, never in the headline result.
 */
enum ReviewerType: string
{
    case ContestJudge = 'contest_judge';
    case EmployerHr = 'employer_hr';
    case Merchant = 'merchant';
    case Bid = 'bid';
    case Friend = 'friend';
    case PersonalContact = 'personal_contact';
    case Public = 'public';

    public function label(): string
    {
        return match ($this) {
            self::ContestJudge => 'Contest judge',
            self::EmployerHr => 'Employer HR',
            self::Merchant => 'Merchant',
            self::Bid => 'BID',
            self::Friend => 'Friend',
            self::PersonalContact => 'Personal contact (knows Finn)',
            self::Public => 'Public judge access',
        };
    }

    /** Blind adjudication (ADR 0011, 0019): who may vote. Not the public link, friends or other types. */
    public function mayAdjudicate(): bool
    {
        return $this->countsInAdjudicationHeadline() || $this === self::PersonalContact;
    }

    /** Only these votes make the headline adjudication result; personal contacts are reported separately. */
    public function countsInAdjudicationHeadline(): bool
    {
        return in_array($this, [self::Bid, self::ContestJudge], true);
    }
}
