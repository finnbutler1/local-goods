<?php

namespace App\Http\Controllers\Projections;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Merchant\RedemptionsExportController;
use App\Support\Projections\Assumptions;
use App\Support\Projections\BusinessModel;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The business model (ADR 0030): the full projected P&L per scenario, what
 * one BID needs to break even, the assumptions with their sources and the
 * lessons from a 2013 prepaid-card model. Signed in only (reviewers, BID
 * admin, platform admin); never linked for logged-out visitors. Every
 * figure is labelled "Projection, not results". Reads only.
 */
class BusinessModelController extends Controller
{
    use ResolvesProjection;

    /** Employer fees the sensitivity line tries, per participating employee per month. */
    public const SENSITIVITY_FEES = [8, 10];

    public const CSV_HEADERS = [
        'Scenario', 'Case', 'Year', 'BIDs', 'Employers', 'Participating employees', 'Merchants',
        'BID initiation fees', 'Employer platform fees', 'Merchant fees', 'Interchange', 'Revenue',
        'Stripe: employer purchases', 'Stripe Connect', 'AI fraud checks', 'Hosting', 'BID partner share', 'Cost of revenue', 'Gross profit',
        'Customer support', 'Sales and marketing', 'G&A, legal and compliance', 'Payroll', 'Operating expenses', 'EBITDA',
        'Spent at independent merchants', 'Visits', 'Customer funds held at year end (not revenue)',
    ];

    public function show(Request $request): View
    {
        [$scenario, $case] = $this->choice($request, 'b');
        $model = BusinessModel::fromConfig();
        $projection = $model->run($scenario, $case);

        return view('projections.business-model', [
            'scenario' => $scenario,
            'case' => $case,
            'model' => $model,
            'projection' => $projection,
            'years' => $projection->years(),
            'whatItTakes' => $model->whatItTakes($case),
            'sensitivity' => collect(self::SENSITIVITY_FEES)
                ->mapWithKeys(fn (int $fee) => [$fee => $model->with(['employer_platform_fee' => $fee])->run($scenario, $case)->breakEvenMonth()])
                ->all(),
            'comparison' => collect(array_keys(config('projections.scenarios')))
                ->mapWithKeys(fn (string $s) => [$s => $model->run($s, $case)])
                ->all(),
            'source' => config('impact.recirculation'),
        ]);
    }

    public function csv(): StreamedResponse
    {
        $model = BusinessModel::fromConfig();

        return response()->streamDownload(function () use ($model) {
            $out = fopen('php://output', 'w');
            $put = fn (array $row) => fputcsv($out, array_map(fn ($v) => RedemptionsExportController::cell((string) $v), $row), escape: '');

            $put([config('projections.label').'. Every figure comes from the assumptions in docs/finance/MODEL.md. Customer funds are never revenue; unspent credits stay the employer\'s money.']);
            $put(self::CSV_HEADERS);
            foreach (self::rows($model) as $row) {
                $put($row);
            }

            $put([]);
            $put(['Scenario', 'Case', 'Break-even month (monthly EBITDA stays above 0, looked for up to month '.config('projections.break_even_search_months').')', 'Peak cash needed', 'Peak cash month']);
            foreach (config('projections.scenarios') as $key => $scenario) {
                foreach (Assumptions::CASES as $case) {
                    $p = $model->run($key, $case);
                    $peak = $p->peakCashNeeded();
                    $put([$scenario['name'], $case, $p->breakEvenMonth() ?? 'none', round($peak['amount']), $peak['month']]);
                }
            }

            $put([]);
            $put(['What it takes: one Golden Triangle BID, fully enrolled, monthly EBITDA at 0', 'Case', 'Participating employees needed', 'Employers needed', 'At employees per employer', 'Employer fee now', 'Employer fee that breaks even', 'At participating employees']);
            foreach (Assumptions::CASES as $case) {
                $w = $model->whatItTakes($case);
                $put(['', $case, $w->employees ?? 'not reachable', $w->employers ?? 'not reachable', round($w->employeesPerEmployer), number_format($w->currentFee, 2), $w->breakEvenFee === null ? 'not reachable' : number_format($w->breakEvenFee, 2), round($w->participation)]);
            }

            fclose($out);
        }, 'local-goods-business-model-projection.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return list<list<string|int>> one row per scenario, case and year, whole dollars */
    public static function rows(BusinessModel $model): array
    {
        $rows = [];
        foreach (config('projections.scenarios') as $key => $scenario) {
            foreach (Assumptions::CASES as $case) {
                foreach ($model->run($key, $case)->years() as $y) {
                    $rows[] = [
                        $scenario['name'], $case, $y['year'], $y['bids'], (int) round($y['employers']), (int) round($y['employees']), (int) round($y['merchants']),
                        ...array_map(fn ($line) => (int) round($y[$line]), [
                            'bid_fees', 'platform_fees', 'merchant_fees', 'interchange', 'revenue',
                            'stripe_funding', 'stripe_connect', 'ai_checks', 'hosting', 'bid_share', 'cost_of_revenue', 'gross_profit',
                            'support', 'sales_marketing', 'g_and_a', 'payroll', 'opex', 'ebitda',
                            'spend', 'visits', 'customer_funds',
                        ]),
                    ];
                }
            }
        }

        return $rows;
    }
}
