<?php

namespace App\Enums;

/**
 * Why a person denied a held redemption, which decides where its reserved
 * credit goes (ADR 0016). Suspected abuse sends it back to the employer's
 * paid pool, so someone colluding can't simply try again with it; "no
 * purchase happened" (a mistaken or duplicate tap) gives it back to the
 * employee. Either way it's one new ledger entry, never an edit.
 */
enum DenialReason: string
{
    case SuspectedAbuse = 'suspected_abuse';
    case NoPurchase = 'no_purchase';

    public function label(): string
    {
        return match ($this) {
            self::SuspectedAbuse => 'Suspected abuse: credit goes back to the employer',
            self::NoPurchase => 'No purchase happened (a mistake): credit goes back to the employee',
        };
    }

    /** Which ledger account gets the credit back: 'employer' or 'employee'. */
    public function returnsTo(): string
    {
        return match ($this) {
            self::SuspectedAbuse => 'employer',
            self::NoPurchase => 'employee',
        };
    }
}
