<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Redemption;
use App\Support\Money;
use App\Support\TaxBreakdown;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A CSV of the merchant's Local Goods redemptions with subtotal, DC sales
 * tax and total (ADR 0027), for their own records. Customers by initials
 * only (ADR 0014). Reads only; a judge gets the demo merchant's.
 */
class RedemptionsExportController extends Controller
{
    use ResolvesMerchant;

    public const HEADERS = ['Date (DC time)', 'Confirmation', 'How', 'Status', 'Customer', 'Items', 'Subtotal', 'Tax', 'Total', 'Tax note'];

    public function __invoke(Request $request): StreamedResponse
    {
        $merchant = $this->merchant($request);
        $redemptions = $merchant->redemptions()->with(['employee', 'items', 'decision'])->latest()->latest('id')->get();

        return response()->streamDownload(function () use ($redemptions) {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::HEADERS, escape: '');

            foreach ($redemptions as $redemption) {
                fputcsv($out, array_map(self::cell(...), self::row($redemption)), escape: '');
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
            match (true) {
                $tax?->isAdded() => implode('; ', array_map(fn ($line) => TaxBreakdown::lineLabel($line['label'], $line['rate_bps']).' '.Money::format($line['tax_cents']), $tax->lines)),
                $tax !== null => 'Amount includes any tax charged by the shop',
                default => 'Tax not itemized (before tax display)',
            },
        ];
    }

    /** A spreadsheet would run a cell starting with = + - @ (or a tab or return) as a formula; a leading ' stops it. */
    public static function cell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
