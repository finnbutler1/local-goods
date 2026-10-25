<?php

namespace App\Services\Corrections;

use App\Models\Correction;
use App\Models\CorrectionItem;
use App\Models\CreditPurchase;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Models\RedemptionItem;
use App\Support\MerchantFee;
use App\Support\Money;
use App\Support\SalesTax;
use InvalidArgumentException;

/**
 * What is left to correct on an item, and what a refund of it comes to
 * (ADR 0028). Every approved, processing, completed or failed correction
 * counts against the item; requested ones don't (nothing is decided yet),
 * nor do rejected or closed ones. CorrectionDecisions re-checks all of this
 * inside a transaction that has locked the item, so two approvals at once
 * can never refund more than was paid.
 */
class CorrectionLimits
{
    /** Cents of this redemption already refunded or on their way back. */
    public static function redemptionRefundedCents(Redemption $redemption, ?int $except = null): int
    {
        return (int) self::counted($redemption, $except)->sum('amount_cents');
    }

    public static function redemptionLeftCents(Redemption $redemption): int
    {
        return $redemption->amount_cents - self::redemptionRefundedCents($redemption);
    }

    /** @return array<int, int> redemption item id => quantity already refunded or on its way back */
    public static function refundedQuantities(Redemption $redemption, ?int $except = null): array
    {
        return CorrectionItem::whereIn('correction_id', self::counted($redemption, $except)->select('id'))
            ->groupBy('redemption_item_id')->selectRaw('redemption_item_id, sum(quantity) as quantity')
            ->pluck('quantity', 'redemption_item_id')->map(fn ($q) => (int) $q)->all();
    }

    /**
     * A refund of some of a redemption's menu items. Their price comes from
     * the item lines and their tax from the redemption's own snapshot (the
     * rates stored when it was made, never today's config). Tax is worked out
     * cumulatively per category: tax on everything refunded so far minus tax
     * already refunded, and a fully refunded category returns exactly its
     * stored tax. So rounding across several partial refunds can never give
     * back more tax than was charged.
     *
     * @param  array<int, int>  $quantities  redemption item id => how many to refund now
     * @return array{amount_cents: int, subtotal_cents: int, tax_cents: int, tax_lines: list<array<string, mixed>>, items: list<array{redemption_item_id: int, quantity: int, amount_cents: int}>}
     */
    public static function itemRefund(Redemption $redemption, array $quantities, ?int $except = null): array
    {
        $items = $redemption->items()->get()->keyBy('id');
        $prior = self::refundedQuantities($redemption, $except);
        $quantities = array_filter(array_map('intval', $quantities), fn ($q) => $q !== 0);

        if ($quantities === []) {
            throw new InvalidArgumentException('Choose at least one item to refund.');
        }

        $rows = [];
        foreach ($quantities as $id => $quantity) {
            $item = $items->get($id) ?? throw new InvalidArgumentException('That item is not part of this redemption.');
            $left = $item->quantity - ($prior[$id] ?? 0);

            if ($quantity < 0 || $quantity > $left) {
                throw new InvalidArgumentException('Only '.$left.' of '.$item->name.' can still be refunded.');
            }

            $rows[] = ['redemption_item_id' => $item->id, 'quantity' => $quantity, 'amount_cents' => $item->unit_price_cents * $quantity];
        }

        $lines = [];
        $after = $prior;
        foreach ($quantities as $id => $quantity) {
            $after[$id] = ($after[$id] ?? 0) + $quantity;
        }

        foreach (self::taxLines($redemption) as $category => $line) {
            $before = self::taxOn($items, $prior, $category, $line);
            $now = self::taxOn($items, $after, $category, $line);
            $taxable = $now['taxable'] - $before['taxable'];

            if ($taxable > 0) {
                $lines[] = ['category' => $category, 'label' => $line['label'], 'rate_bps' => $line['rate_bps'],
                    'taxable_cents' => $taxable, 'tax_cents' => $now['tax'] - $before['tax']];
            }
        }

        $subtotal = array_sum(array_column($rows, 'amount_cents'));
        $tax = array_sum(array_column($lines, 'tax_cents'));

        return ['amount_cents' => $subtotal + $tax, 'subtotal_cents' => $subtotal, 'tax_cents' => $tax, 'tax_lines' => $lines, 'items' => $rows];
    }

    /**
     * The share of the Local Goods merchant fee (ADR 0029) a refund of this
     * many cents gives back, worked out cumulatively like the tax: the share
     * of everything refunded so far, less what counted refunds already gave
     * back. So over all of them exactly the fee comes back once the whole
     * sale is refunded, and never more, and no refund's share is more than
     * the refund. 0 for a redemption with no fee.
     */
    public static function feeShare(Redemption $redemption, int $refundCents, ?int $except = null): int
    {
        $fee = (int) $redemption->merchant_fee_cents;

        if ($fee === 0 || $refundCents <= 0) {
            return 0;
        }

        $returned = (int) self::counted($redemption, $except)->sum('fee_cents');
        $after = MerchantFee::returnedAfter($fee, $redemption->amount_cents, self::redemptionRefundedCents($redemption, $except) + $refundCents);

        return max(0, min($refundCents, $after - $returned, $fee - $returned));
    }

    /** A refund of a typed-in amount (no items): any whole-cent amount up to what is left. No tax is split out. */
    public static function amountRefund(Redemption $redemption, int $amountCents, ?int $except = null): int
    {
        $left = $redemption->amount_cents - self::redemptionRefundedCents($redemption, $except);

        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Enter an amount more than $0.00.');
        }

        if ($amountCents > $left) {
            throw new InvalidArgumentException('Only '.Money::format($left).' of this redemption can still be refunded.');
        }

        return $amountCents;
    }

    /**
     * Which payments an employer refund goes back to: newest first (merchant
     * transfers are funded oldest first, so the newest payments are the
     * least likely to have been claimed), each at most what is left of it.
     *
     * @param  iterable<CreditPurchase>  $purchases  the employer's paid purchases
     * @return array{plan: list<array{0: CreditPurchase, 1: int}>, short_cents: int}
     */
    public static function employerPlan(iterable $purchases, int $amountCents): array
    {
        $plan = [];
        $left = $amountCents;

        foreach (collect($purchases)->sortBy([['purchased_at', 'desc'], ['id', 'desc']]) as $purchase) {
            if ($left <= 0) {
                break;
            }
            if (($take = min($left, $purchase->refundableCents())) > 0) {
                $plan[] = [$purchase, $take];
                $left -= $take;
            }
        }

        return ['plan' => $plan, 'short_cents' => max(0, $left)];
    }

    /**
     * How a refund of this redemption would be paid, for the queue and the
     * approval: reversing its sent Stripe transfer, netted (simulated), or
     * not yet (the transfer hasn't gone out, or was reversed in the Stripe
     * dashboard).
     *
     * @return array{path: 'stripe'|'simulated'|'blocked', payout: ?MerchantPayout, text: string}
     */
    public static function refundPath(Redemption $redemption): array
    {
        $payout = $redemption->relationLoaded('stripePayout') ? $redemption->stripePayout : MerchantPayout::where('redemption_id', $redemption->id)->first();

        return match (true) {
            $payout === null => ['path' => 'simulated', 'payout' => null,
                'text' => 'No Stripe transfer behind it (simulated): refunded at once and deducted from what the merchant is owed.'],
            $payout->status === MerchantPayout::STATUS_SENT => ['path' => 'stripe', 'payout' => $payout,
                'text' => 'Paid through Stripe (transfer '.$payout->stripe_transfer_id.'): that part of the transfer is reversed, and the credits come back when Stripe confirms.'],
            $payout->status === MerchantPayout::STATUS_REVERSED => ['path' => 'blocked', 'payout' => $payout, 'text' => CorrectionDecisions::REVERSED_IN_DASHBOARD],
            default => ['path' => 'blocked', 'payout' => $payout, 'text' => CorrectionDecisions::NOT_SENT_YET],
        };
    }

    /** @return array<string, array<string, mixed>> the redemption's stored tax lines, by category */
    private static function taxLines(Redemption $redemption): array
    {
        return collect($redemption->tax_lines ?? [])->keyBy('category')->all();
    }

    /**
     * Tax on the given quantities of one category's items, at the stored
     * rate; the stored line itself once every item in it is counted.
     *
     * @return array{taxable: int, tax: int}
     */
    private static function taxOn($items, array $quantities, string $category, array $line): array
    {
        $taxable = $items->filter(fn (RedemptionItem $i) => $i->tax_category === $category)
            ->sum(fn (RedemptionItem $i) => $i->unit_price_cents * ($quantities[$i->id] ?? 0));

        $tax = $taxable >= $line['taxable_cents'] ? (int) $line['tax_cents'] : min((int) $line['tax_cents'], SalesTax::on($taxable, (int) $line['rate_bps']));

        return ['taxable' => (int) $taxable, 'tax' => $tax];
    }

    private static function counted(Redemption $redemption, ?int $except)
    {
        return Correction::where('redemption_id', $redemption->id)
            ->where('type', Correction::TYPE_REDEMPTION_REFUND)
            ->whereIn('status', Correction::COUNTED_STATUSES)
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except));
    }
}
