<?php

namespace Tests\Concerns;

use App\Models\MerchantFeeChange;
use App\Support\MerchantFee;

/**
 * Turns the merchant fee (ADR 0029) on for a test, the way a confirmed
 * change records it. Tests only: the seed and live never set a fee.
 */
trait SetsMerchantFee
{
    protected function setMerchantFee(int $bps, int $fixedCents = 0): MerchantFee
    {
        $current = MerchantFee::current();
        MerchantFeeChange::create([
            'fee_bps' => $bps, 'fixed_cents' => $fixedCents,
            'previous_bps' => $current->bps, 'previous_fixed_cents' => $current->fixedCents, 'reason' => 'Test fee',
        ]);

        return MerchantFee::current();
    }
}
