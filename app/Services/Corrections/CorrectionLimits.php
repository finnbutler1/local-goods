<?php

namespace App\Services\Corrections;

use App\Models\Correction;
use App\Models\Redemption;

/**
 * What is left to correct on an item (ADR 0028). Every approved, processing,
 * completed or failed correction counts against the item; requested ones
 * don't (nothing is decided yet), nor do rejected or closed ones. The
 * approval re-checks all of this inside a locked transaction; these reads
 * are also used to decide what the screens offer.
 */
class CorrectionLimits
{
    /** Cents of this redemption already refunded or on their way back. */
    public static function redemptionRefundedCents(Redemption $redemption, ?int $except = null): int
    {
        return (int) Correction::where('redemption_id', $redemption->id)
            ->where('type', Correction::TYPE_REDEMPTION_REFUND)
            ->whereIn('status', Correction::COUNTED_STATUSES)
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))
            ->sum('amount_cents');
    }

    public static function redemptionLeftCents(Redemption $redemption): int
    {
        return $redemption->amount_cents - self::redemptionRefundedCents($redemption);
    }
}
