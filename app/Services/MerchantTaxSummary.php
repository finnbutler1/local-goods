<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\Redemption;
use App\Support\TaxBreakdown;
use Illuminate\Support\Carbon;

/**
 * "Tax collected" on the merchant dashboard (ADR 0027): the DC sales tax
 * added to this merchant's cleared menu orders, this calendar month and
 * last, in DC time. For the merchant's records only; the merchant files and
 * remits the tax. Typed-in amounts already include whatever tax the shop
 * charged and are never split out, so they are not counted here.
 */
class MerchantTaxSummary
{
    /** @return array{this_month: array{label: string, tax_cents: int, orders: int}, last_month: array{label: string, tax_cents: int, orders: int}} */
    public static function for(Merchant $merchant, ?Carbon $at = null): array
    {
        $at ??= now();

        return [
            'this_month' => self::month($merchant, $at),
            'last_month' => self::month($merchant, $at->copy()->setTimezone(config('fraud.timezone'))->startOfMonth()->subDay()),
        ];
    }

    /** @return array{label: string, tax_cents: int, orders: int} */
    private static function month(Merchant $merchant, Carbon $at): array
    {
        [$start, $end] = EmployerProgram::month($at);
        $rows = $merchant->redemptions()
            ->where('status', Redemption::STATUS_APPROVED)
            ->where('tax_basis', TaxBreakdown::ADDED)
            ->where('created_at', '>=', $start)->where('created_at', '<', $end);

        return [
            'label' => $start->copy()->setTimezone(config('fraud.timezone'))->format('F Y'),
            'tax_cents' => (int) (clone $rows)->sum('tax_cents'),
            'orders' => (clone $rows)->count(),
        ];
    }
}
