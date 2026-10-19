<?php

namespace App\Support;

/**
 * Turns stored fraud signals into labelled, human-readable rows for the BID admin view.
 */
class SignalPresenter
{
    /**
     * Says exactly what SignalCalculator checks for is_round_number, built
     * from the same config values. Display text only.
     */
    public static function roundAmountLabel(): string
    {
        $dollars = fn (int $cents) => $cents % 100 === 0 ? '$'.number_format(intdiv($cents, 100)) : Money::format($cents);

        return 'Round amount (a multiple of '.$dollars((int) config('fraud.round_number_step_cents'))
            .', and at least '.$dollars((int) config('fraud.round_number_min_cents')).')';
    }

    /**
     * @param  array<string, mixed>  $s
     * @return list<array{label: string, value: string, flagged: bool}>
     */
    public static function rows(array $s): array
    {
        $yesNo = fn ($v) => $v ? 'Yes' : 'No';
        $range = $s['category_typical_range_cents'] ?? null;

        return [
            [
                'label' => self::roundAmountLabel(),
                'value' => $yesNo($s['is_round_number'] ?? false),
                'flagged' => (bool) ($s['is_round_number'] ?? false),
            ],
            [
                'label' => 'Uses full balance',
                'value' => $yesNo($s['is_full_balance'] ?? false).' (balance was '.Money::format($s['balance_before_cents'] ?? 0).')',
                'flagged' => (bool) ($s['is_full_balance'] ?? false),
            ],
            [
                'label' => 'Visits to this merchant',
                'value' => ($s['same_merchant_visits_last_7_days'] ?? 0).' in 7 days, '.($s['same_merchant_visits_last_30_days'] ?? 0).' in 30 days',
                'flagged' => ($s['same_merchant_visits_last_7_days'] ?? 0) >= 3,
            ],
            [
                'label' => 'vs. employee’s usual',
                'value' => isset($s['amount_vs_employee_median'])
                    ? $s['amount_vs_employee_median'].'× their median of '.Money::format($s['employee_median_cents'])
                    : 'No history yet',
                'flagged' => ($s['amount_vs_employee_median'] ?? 0) >= 3,
            ],
            [
                'label' => 'Ticket size for category',
                'value' => ucfirst($s['amount_vs_category_range'] ?? 'unknown')
                    .($range ? ' typical '.$s['merchant_category'].' range ('.Money::format($range[0]).'–'.Money::format($range[1]).')' : ''),
                'flagged' => ($s['amount_vs_category_range'] ?? null) === 'above',
            ],
            [
                'label' => 'Outside business hours',
                'value' => $yesNo($s['outside_business_hours'] ?? false).' ('.sprintf('%02d:00', $s['local_hour'] ?? 0).' local)',
                'flagged' => (bool) ($s['outside_business_hours'] ?? false),
            ],
        ];
    }
}
