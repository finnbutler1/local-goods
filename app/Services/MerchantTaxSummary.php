<?php

namespace App\Services;

use App\Models\Correction;
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
 *
 * Refunds (ADR 0028): the tax on refunded menu items, from the order's own
 * snapshot, comes off in the month the refund completed (DC time).
 * tax_cents stays the tax added at checkout; net_tax_cents is what the
 * merchant collected after refunds, the figure "Tax collected" shows.
 */
class MerchantTaxSummary
{
    /** @return array{this_month: array{label: string, tax_cents: int, orders: int, refunded_tax_cents: int, refunds: int, net_tax_cents: int}, last_month: array{label: string, tax_cents: int, orders: int, refunded_tax_cents: int, refunds: int, net_tax_cents: int}} */
    public static function for(Merchant $merchant, ?Carbon $at = null): array
    {
        $at ??= now();

        return [
            'this_month' => self::month($merchant, $at),
            'last_month' => self::month($merchant, $at->copy()->setTimezone(config('fraud.timezone'))->startOfMonth()->subDay()),
        ];
    }

    /** @return array{label: string, tax_cents: int, orders: int, refunded_tax_cents: int, refunds: int, net_tax_cents: int} */
    private static function month(Merchant $merchant, Carbon $at): array
    {
        [$start, $end] = EmployerProgram::month($at);
        $rows = $merchant->redemptions()
            ->where('status', Redemption::STATUS_APPROVED)
            ->where('tax_basis', TaxBreakdown::ADDED)
            ->where('created_at', '>=', $start)->where('created_at', '<', $end);
        $refunds = Correction::where('type', Correction::TYPE_REDEMPTION_REFUND)->where('status', Correction::STATUS_COMPLETED)
            ->where('merchant_id', $merchant->id)->where('tax_cents', '>', 0)
            ->where('completed_at', '>=', $start)->where('completed_at', '<', $end);
        $tax = (int) (clone $rows)->sum('tax_cents');
        $refunded = (int) (clone $refunds)->sum('tax_cents');

        return [
            'label' => $start->copy()->setTimezone(config('fraud.timezone'))->format('F Y'),
            'tax_cents' => $tax,
            'orders' => (clone $rows)->count(),
            'refunded_tax_cents' => $refunded,
            'refunds' => (clone $refunds)->count(),
            'net_tax_cents' => $tax - $refunded,
        ];
    }
}
