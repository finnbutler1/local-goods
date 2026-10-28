<?php

namespace App\Http\Controllers\BidAdmin;

use App\Http\Controllers\Controller;
use App\Services\BidImpactMetrics;
use App\Support\Money;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The district's aggregate data as a CSV (ADR 0031), so the partnership's
 * data terms are true: the BID can export it at any time. The same
 * numbers as /bid-admin/impact, counted from the ledger and net of
 * refunds. Counts and totals only: no employee, employer or merchant is
 * named, and no Local Goods fee or revenue is in it.
 */
class ImpactExportController extends Controller
{
    public const HEADERS = ['Period', 'Visits', 'Spent at merchants, net of refunds', 'Refunded', 'Merchants reached', 'Employees participating', 'Employers participating'];

    public function __invoke(BidImpactMetrics $metrics): StreamedResponse
    {
        $m = $metrics->for();
        $tz = config('fraud.timezone');
        $rows = [
            ['Since launch', $m['all_time']['visits'], Money::toDollars($m['all_time']['spent_cents']), Money::toDollars($m['refunded']['all_time']),
                $m['all_time']['merchants'], $m['all_time']['employees'], $m['all_time']['employers']],
            [$m['month']->format('F Y').' (this month)', $m['this_month']['visits'], Money::toDollars($m['this_month']['spent_cents']), Money::toDollars($m['refunded']['this_month']),
                $m['this_month']['merchants'], $m['this_month']['employees'], $m['this_month']['employers']],
        ];
        foreach ($m['weeks'] as $week) {
            $rows[] = ['Week of '.$week['start']->copy()->setTimezone($tz)->format('Y-m-d'), $week['visits'], Money::toDollars($week['cents']), Money::toDollars($week['refunded_cents']), '', '', ''];
        }

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::HEADERS, escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map('strval', $row), escape: '');
            }
            fclose($out);
        }, 'golden-triangle-impact-'.now()->setTimezone($tz)->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
