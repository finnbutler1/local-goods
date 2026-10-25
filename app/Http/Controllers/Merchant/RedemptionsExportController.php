<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Correction;
use App\Models\Redemption;
use App\Support\Money;
use App\Support\TaxBreakdown;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A CSV of the merchant's Local Goods redemptions with subtotal, DC sales
 * tax and total (ADR 0027), for their own records. Customers by initials
 * only (ADR 0014). Reads only; a judge gets the demo merchant's. Completed
 * refunds (ADR 0028) are rows of their own, dated when they completed,
 * with negative amounts and the refunded tax from the order's snapshot.
 * Local Goods fee and You receive (ADR 0029) come from each redemption's
 * fee snapshot; a refund row gives back its share of the fee.
 */
class RedemptionsExportController extends Controller
{
    use ResolvesMerchant;

    public const HEADERS = ['Date (DC time)', 'Confirmation', 'How', 'Status', 'Customer', 'Items', 'Subtotal', 'Tax', 'Total', 'Local Goods fee', 'You receive', 'Tax note'];

    public function __invoke(Request $request): StreamedResponse
    {
        $merchant = $this->merchant($request);
        $redemptions = $merchant->redemptions()->with(['employee', 'items', 'decision'])->get()
            ->map(fn (Redemption $r) => [$r->created_at, $r->id, self::row($r)]);
        $refunds = Correction::with(['redemption.employee', 'items.redemptionItem'])->where('merchant_id', $merchant->id)
            ->where('type', Correction::TYPE_REDEMPTION_REFUND)->where('status', Correction::STATUS_COMPLETED)->get()
            ->map(fn (Correction $c) => [$c->completed_at, $c->redemption_id, self::refundRow($c)]);
        // Newest first, refunds among the orders by when they completed.
        $rows = $redemptions->concat($refunds)->sortBy([fn ($a, $b) => $b[0] <=> $a[0], fn ($a, $b) => $b[1] <=> $a[1]])->pluck(2);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::HEADERS, escape: '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(self::cell(...), $row), escape: '');
            }

            fclose($out);
        }, 'local-goods-redemptions-'.now()->setTimezone(config('fraud.timezone'))->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return list<string> */
    public static function row(Redemption $redemption): array
    {
        $tax = $redemption->taxBreakdown();

        return [
            $redemption->created_at->setTimezone(config('fraud.timezone'))->format('Y-m-d H:i'),
            $redemption->reference(),
            $redemption->channelLabel(),
            match (true) {
                $redemption->isApproved() => 'Cleared',
                $redemption->isUnderReview() => 'Waiting for review, not owed yet',
                default => ($redemption->cancelledLabel() ?? 'Denied').', not owed',
            },
            $redemption->employee->initials(),
            $redemption->itemsSummary() ?? '',
            $tax?->isAdded() ? Money::toDollars($tax->subtotalCents) : '',
            $tax?->isAdded() ? Money::toDollars($tax->taxCents) : '',
            Money::toDollars($redemption->amount_cents),
            // The Local Goods merchant fee (ADR 0029), from the redemption's own snapshot; charged only once it cleared.
            $redemption->isApproved() ? Money::toDollars((int) $redemption->merchant_fee_cents) : '',
            $redemption->isApproved() ? Money::toDollars($redemption->amount_cents - (int) $redemption->merchant_fee_cents) : '',
            match (true) {
                $tax?->isAdded() => implode('; ', array_map(fn ($line) => TaxBreakdown::lineLabel($line['label'], $line['rate_bps']).' '.Money::format($line['tax_cents']), $tax->lines)),
                $tax !== null => 'Amount includes any tax charged by the shop',
                default => 'Tax not itemized (before tax display)',
            },
        ];
    }

    /** A completed refund: dated when it completed, amounts negative, refunded tax from the order's snapshot. @return list<string> */
    public static function refundRow(Correction $refund): array
    {
        $redemption = $refund->redemption;
        $tax = $refund->taxBreakdown();
        $minus = fn (int $cents) => $cents === 0 ? '0.00' : '-'.Money::toDollars($cents);

        return [
            $refund->completed_at->setTimezone(config('fraud.timezone'))->format('Y-m-d H:i'),
            $redemption->reference(),
            $redemption->channelLabel(),
            'Refund',
            $redemption->employee->initials(),
            $refund->items->map(fn ($i) => $i->quantity.'× '.$i->redemptionItem?->name)->implode(', '),
            $tax ? $minus($tax->subtotalCents) : '',
            $tax ? $minus($tax->taxCents) : '',
            $minus($refund->amount_cents),
            $minus((int) $refund->fee_cents),
            $minus($refund->amount_cents - (int) $refund->fee_cents),
            $tax
                ? 'Refunded tax: '.implode('; ', array_map(fn ($line) => TaxBreakdown::lineLabel($line['label'], $line['rate_bps']).' '.Money::format($line['tax_cents']), $tax->lines))
                : 'Amount includes any tax charged by the shop',
        ];
    }

    /**
     * A spreadsheet would run a cell starting with = + - @ (or a tab or
     * return) as a formula; a leading ' stops it. A plain negative amount
     * ("-4.13", a refund row) is a number, not a formula, so it stays one.
     */
    public static function cell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) && ! preg_match('/^-\d+(\.\d+)?$/', $value) ? "'".$value : $value;
    }
}
