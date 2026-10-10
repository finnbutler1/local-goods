<?php

namespace App\Services\Fraud;

use App\Models\Employee;
use App\Models\Merchant;
use Illuminate\Support\Carbon;

/**
 * Turns a proposed redemption into anonymous signals for Redemption Fraud
 * Detection. No names or identifiers are included in the output.
 */
class SignalCalculator
{
    /**
     * @return array<string, mixed>
     */
    public function calculate(Employee $employee, Merchant $merchant, int $amountCents, ?Carbon $at = null): array
    {
        $at ??= Carbon::now();
        $local = $at->copy()->setTimezone(config('fraud.timezone'));
        $balance = $employee->balanceCents();

        $pastAmounts = $employee->redemptions()->pluck('amount_cents')->all();
        $median = $this->median($pastAmounts);

        [$typicalMin, $typicalMax] = config("fraud.category_ticket_ranges.{$merchant->category}", [null, null]);

        $visitsSince = fn (Carbon $since) => $employee->redemptions()
            ->where('merchant_id', $merchant->id)
            ->where('created_at', '>=', $since)
            ->count();

        $hours = config('fraud.business_hours');

        return [
            'amount_cents' => $amountCents,
            'balance_before_cents' => $balance,
            'is_round_number' => $amountCents >= config('fraud.round_number_min_cents')
                && $amountCents % config('fraud.round_number_step_cents') === 0,
            'is_full_balance' => $balance > 0 && $amountCents >= $balance * config('fraud.full_balance_ratio'),
            'same_merchant_visits_last_7_days' => $visitsSince($at->copy()->subDays(7)),
            'same_merchant_visits_last_30_days' => $visitsSince($at->copy()->subDays(30)),
            'employee_past_redemptions' => count($pastAmounts),
            'employee_median_cents' => $median,
            'amount_vs_employee_median' => $median ? round($amountCents / $median, 2) : null,
            'merchant_category' => $merchant->category,
            'category_typical_range_cents' => $typicalMin === null ? null : [$typicalMin, $typicalMax],
            'amount_vs_category_range' => match (true) {
                $typicalMin === null => 'unknown',
                $amountCents < $typicalMin => 'below',
                $amountCents > $typicalMax => 'above',
                default => 'within',
            },
            'local_hour' => $local->hour,
            'outside_business_hours' => $local->hour < $hours['open'] || $local->hour >= $hours['close'],
        ];
    }

    /**
     * @param  list<int>  $values
     */
    private function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2
            ? $values[$middle]
            : intdiv($values[$middle - 1] + $values[$middle], 2);
    }
}
