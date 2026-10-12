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
        $pastAmounts = $employee->redemptions()->pluck('amount_cents')->all();

        $visitsSince = fn (Carbon $since) => $employee->redemptions()
            ->where('merchant_id', $merchant->id)
            ->where('created_at', '>=', $since)
            ->count();

        return $this->fromFacts(
            amountCents: $amountCents,
            balanceCents: $employee->balanceCents(),
            pastRedemptions: count($pastAmounts),
            medianCents: $this->median($pastAmounts),
            visitsLast7Days: $visitsSince($at->copy()->subDays(7)),
            visitsLast30Days: $visitsSince($at->copy()->subDays(30)),
            category: $merchant->category,
            localHour: $local->hour,
        );
    }

    /**
     * The signal rules themselves, given plain facts. The live check and the
     * fraud:eval command both go through here, so they cannot drift apart.
     *
     * @return array<string, mixed>
     */
    public function fromFacts(
        int $amountCents,
        int $balanceCents,
        int $pastRedemptions,
        ?int $medianCents,
        int $visitsLast7Days,
        int $visitsLast30Days,
        string $category,
        int $localHour,
    ): array {
        [$typicalMin, $typicalMax] = config("fraud.category_ticket_ranges.{$category}", [null, null]);
        $hours = config('fraud.business_hours');

        return [
            'amount_cents' => $amountCents,
            'balance_before_cents' => $balanceCents,
            'is_round_number' => $amountCents >= config('fraud.round_number_min_cents')
                && $amountCents % config('fraud.round_number_step_cents') === 0,
            'is_full_balance' => $balanceCents > 0 && $amountCents >= $balanceCents * config('fraud.full_balance_ratio'),
            'same_merchant_visits_last_7_days' => $visitsLast7Days,
            'same_merchant_visits_last_30_days' => $visitsLast30Days,
            'employee_past_redemptions' => $pastRedemptions,
            'employee_median_cents' => $medianCents,
            'amount_vs_employee_median' => $medianCents ? round($amountCents / $medianCents, 2) : null,
            'merchant_category' => $category,
            'category_typical_range_cents' => $typicalMin === null ? null : [$typicalMin, $typicalMax],
            'amount_vs_category_range' => match (true) {
                $typicalMin === null => 'unknown',
                $amountCents < $typicalMin => 'below',
                $amountCents > $typicalMax => 'above',
                default => 'within',
            },
            'local_hour' => $localHour,
            'outside_business_hours' => $localHour < $hours['open'] || $localHour >= $hours['close'],
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
