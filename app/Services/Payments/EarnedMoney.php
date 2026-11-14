<?php

namespace App\Services\Payments;

use App\Models\CreditLedgerEntry;
use App\Models\MerchantFeeChange;
use App\Models\Redemption;
use App\Support\BidProgramFee;
use App\Support\EmployerPlatformFee;
use App\Support\MerchantFee;
use App\Support\PlatformFee;
use Illuminate\Support\Carbon;

/**
 * Where the money Local Goods has earned came from (ADR 0034), read from the
 * ledger and the fee audit log, for Where the money is. Read-only. It says
 * what happened, never more: how many sales or purchases were charged, when,
 * the times a fee was switched on, and whether any fee is on now, so the page
 * can't present a one-off as ongoing income.
 */
class EarnedMoney
{
    /**
     * @return array{
     *   charges_now: bool,
     *   merchant_fee_sales: int, merchant_fee_cents: int, first_sale_at: ?Carbon, last_sale_at: ?Carbon,
     *   platform_fee_purchases: int, platform_fee_cents: int,
     *   employer_fee_purchases: int, employer_fee_cents: int,
     *   bid_fee_years: int, bid_fee_cents: int,
     *   fee_windows: list<array{from: Carbon, to: ?Carbon}>
     * }
     */
    public function summary(): array
    {
        $merchantFees = CreditLedgerEntry::where('platform', true)
            ->whereIn('type', [CreditLedgerEntry::TYPE_PLATFORM_MERCHANT_FEE, CreditLedgerEntry::TYPE_PLATFORM_MERCHANT_FEE_RETURNED]);
        $charged = CreditLedgerEntry::where('platform', true)->where('type', CreditLedgerEntry::TYPE_PLATFORM_MERCHANT_FEE)->pluck('redemption_id')->unique();
        $sales = Redemption::whereIn('id', $charged)->orderBy('created_at')->pluck('created_at');
        $platformFees = CreditLedgerEntry::where('platform', true)->where('type', CreditLedgerEntry::TYPE_PLATFORM_FEE);
        $employerFees = CreditLedgerEntry::where('platform', true)->where('type', CreditLedgerEntry::TYPE_EMPLOYER_PLATFORM_FEE);
        $bidFees = CreditLedgerEntry::where('platform', true)->where('type', CreditLedgerEntry::TYPE_BID_PROGRAM_FEE);

        return [
            'charges_now' => ! MerchantFee::current()->isNone() || PlatformFee::enabled() || EmployerPlatformFee::charges() || BidProgramFee::charges(),
            'merchant_fee_sales' => $charged->count(),
            'merchant_fee_cents' => (int) (clone $merchantFees)->sum('amount_cents'),
            'first_sale_at' => $sales->first(),
            'last_sale_at' => $sales->last(),
            'platform_fee_purchases' => (clone $platformFees)->distinct()->count('credit_purchase_id'),
            'platform_fee_cents' => (int) (clone $platformFees)->sum('amount_cents'),
            'employer_fee_purchases' => (clone $employerFees)->distinct()->count('credit_purchase_id'),
            'employer_fee_cents' => (int) (clone $employerFees)->sum('amount_cents'),
            // The BID annual program fee (ADR 0044): one line per paid program year.
            'bid_fee_years' => (clone $bidFees)->count(),
            'bid_fee_cents' => (int) (clone $bidFees)->sum('amount_cents'),
            'fee_windows' => self::merchantFeeWindows(),
        ];
    }

    /**
     * Each stretch of time the merchant fee was on, from the audit log: from
     * a change to a nonzero fee until the next change ("to" null: still on).
     *
     * @return list<array{from: Carbon, to: ?Carbon}>
     */
    public static function merchantFeeWindows(): array
    {
        $windows = [];
        $open = null;
        foreach (MerchantFeeChange::orderBy('id')->get() as $change) {
            $on = $change->fee_bps > 0 || $change->fixed_cents > 0;
            if ($on && $open === null) {
                $open = $change->created_at;
            } elseif (! $on && $open !== null) {
                $windows[] = ['from' => $open, 'to' => $change->created_at];
                $open = null;
            }
        }
        if ($open !== null) {
            $windows[] = ['from' => $open, 'to' => null];
        }

        return $windows;
    }
}
