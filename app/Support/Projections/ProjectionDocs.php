<?php

namespace App\Support\Projections;

use RuntimeException;

/**
 * The projection figures written into the README and docs/finance/MODEL.md
 * (ADR 0030), generated from the business model so the docs can't drift
 * from config/projections.php. Each block sits between
 * <!-- projection:NAME:start --> and <!-- projection:NAME:end -->;
 * `php artisan projections:docs` rewrites them and BusinessModelDocsTest
 * fails when they are out of date.
 */
final class ProjectionDocs
{
    public const FILES = [
        'README.md' => ['readme-table'],
        'docs/finance/MODEL.md' => ['base-case', 'break-even', 'what-it-takes', 'assumptions', 'scenarios'],
    ];

    public function __construct(private readonly BusinessModel $model) {}

    public function block(string $name): string
    {
        return match ($name) {
            'readme-table' => $this->readmeTable(),
            'base-case' => $this->baseCase(),
            'break-even' => $this->breakEven(),
            'what-it-takes' => $this->whatItTakes(),
            'assumptions' => $this->assumptions(),
            'scenarios' => $this->scenarios(),
        };
    }

    /** The document with every block replaced by its generated text. */
    public function apply(string $document, string $file): string
    {
        foreach (self::FILES[$file] as $name) {
            $pattern = '/(<!-- projection:'.$name.':start -->\n).*?(<!-- projection:'.$name.':end -->)/s';
            if (! preg_match($pattern, $document)) {
                throw new RuntimeException("{$file} has no projection:{$name} block.");
            }
            $document = preg_replace_callback($pattern, fn ($m) => $m[1].$this->block($name)."\n".$m[2], $document);
        }

        return $document;
    }

    private function readmeTable(): string
    {
        $rows = ['| Base case | Year 1 | Year 2 | Year 3 | Year 4 |', '|---|---|---|---|---|'];
        foreach (config('projections.scenarios') as $key => $scenario) {
            $years = $this->model->run($key)->years();
            foreach (['revenue' => 'revenue', 'ebitda' => 'EBITDA'] as $line => $label) {
                $rows[] = "| {$scenario['name']}: {$label} | ".implode(' | ', array_map(fn ($y) => Figures::dollars($y[$line]), $years)).' |';
            }
        }

        return implode("\n", $rows);
    }

    private function baseCase(): string
    {
        $out = [];
        foreach (config('projections.scenarios') as $key => $scenario) {
            $years = $this->model->run($key)->years();
            $out[] = "**{$scenario['name']}** (base case)";
            $out[] = '';
            $out[] = '| | Year 1 | Year 2 | Year 3 | Year 4 |';
            $out[] = '|---|---|---|---|---|';
            $out[] = '| BIDs / employers / participating employees (year end) | '.implode(' | ', array_map(fn ($y) => $y['bids'].' / '.Figures::count($y['employers']).' / '.Figures::count($y['employees']), $years)).' |';
            foreach ([
                'revenue' => 'Revenue', 'platform_fees' => '  of which employer platform fees', 'merchant_fees' => '  of which merchant fees', 'bid_fees' => '  of which BID initiation fees',
                'cost_of_revenue' => 'Cost of revenue', 'gross_profit' => 'Gross profit', 'opex' => 'Operating expenses', 'payroll' => '  of which payroll', 'ebitda' => 'EBITDA',
                'spend' => 'Spent at independent merchants', 'visits' => 'Visits', 'customer_funds' => 'Customer funds held at year end (not revenue)',
            ] as $line => $label) {
                $format = $line === 'visits' ? Figures::count(...) : Figures::dollars(...);
                $out[] = '| '.trim($label).' | '.implode(' | ', array_map(fn ($y) => $format($y[$line]), $years)).' |';
            }
            $out[] = '| Gross margin | '.implode(' | ', array_map(fn ($y) => Figures::percent($y['gross_margin']), $years)).' |';
            $out[] = '';
        }

        return rtrim(implode("\n", $out));
    }

    private function breakEven(): string
    {
        $search = config('projections.break_even_search_months');
        $rows = ['| Scenario | Case | Monthly break-even | Peak cash needed |', '|---|---|---|---|'];
        foreach (config('projections.scenarios') as $key => $scenario) {
            foreach (Assumptions::CASES as $case) {
                $p = $this->model->run($key, $case);
                $peak = $p->peakCashNeeded();
                $rows[] = "| {$scenario['name']} | {$case} | ".($p->breakEvenMonth() === null ? "not within {$search} months" : 'month '.$p->breakEvenMonth())
                    .' | '.Figures::dollars($peak['amount']).($peak['still_growing'] ? " (still growing at month {$search})" : " (month {$peak['month']})").' |';
            }
        }

        return implode("\n", $rows);
    }

    private function whatItTakes(): string
    {
        $rows = ['| Case | Employer fee now | Participating employees needed | Employers needed | Employer fee that breaks even | At participating employees |', '|---|---|---|---|---|---|'];
        foreach (Assumptions::CASES as $case) {
            $w = $this->model->whatItTakes($case);
            $rows[] = "| {$case} | ".Figures::exact($w->currentFee).' | '.($w->employees === null ? 'not reachable' : Figures::count($w->employees))
                .' | '.($w->employers === null ? 'not reachable' : Figures::count($w->employers).' at '.Figures::count($w->employeesPerEmployer).' each')
                .' | '.($w->breakEvenFee === null ? 'not reachable' : Figures::exact($w->breakEvenFee)).' | '.Figures::count($w->participation).' |';
        }

        return implode("\n", $rows);
    }

    private function assumptions(): string
    {
        $rows = ['| Key | Assumption | Unit | Low | Base | High | Source |', '|---|---|---|---|---|---|---|'];
        foreach (config('projections.assumptions') as $key => $a) {
            $source = $a['source_url'] ? "[{$a['source']}]({$a['source_url']})" : $a['source'];
            $note = $a['note'] === '' ? '' : ' '.$a['note'];
            $rows[] = "| `{$key}` | {$a['label']} | {$a['unit']} | ".Figures::assumption($a, 'low').' | '.Figures::assumption($a, 'base').' | '.Figures::assumption($a, 'high')
                ." | {$source}.{$note} Checked {$a['verified_on']}. |";
        }

        return str_replace('..', '.', implode("\n", $rows));
    }

    private function scenarios(): string
    {
        $rows = ['| Scenario | BIDs at the end of years 1–4 | Source |', '|---|---|---|'];
        foreach (config('projections.scenarios') as $scenario) {
            $source = $scenario['source_url'] ? "[{$scenario['source']}]({$scenario['source_url']})" : $scenario['source'];
            $rows[] = "| {$scenario['name']} | ".implode(', ', $scenario['bids_by_year_end'])." | {$source}. {$scenario['note']} |";
        }

        return implode("\n", $rows);
    }
}
