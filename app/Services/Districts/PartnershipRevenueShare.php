<?php

namespace App\Services\Districts;

use App\Models\CreditLedgerEntry;
use App\Models\Partnership;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * What a BID would earn from its partnership's revenue share (ADR 0031),
 * worked out from real activity in its district. Display only: it reads the
 * ledger and moves no money, and nothing pays a BID in this phase.
 *
 * Local Goods' revenue in the district is every platform-account ledger
 * line tied to it: merchant fees on redemptions at the district's merchants
 * (net of fees given back on refunds) and platform fees on credits bought
 * by employers whose program runs there. Both fees are 0 today, so the
 * revenue and the share are $0.00 whatever the rate.
 */
class PartnershipRevenueShare
{
    /** @return array{revenue_cents: int, share_cents: int} */
    public function between(Partnership $partnership, ?Carbon $from, ?Carbon $until): array
    {
        $revenue = (int) $this->platformLines($partnership->district_id)
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from->copy()->utc()))
            ->when($until, fn ($q) => $q->where('created_at', '<', $until->copy()->utc()))
            ->sum('amount_cents');

        return ['revenue_cents' => $revenue, 'share_cents' => self::shareOf($revenue, (int) $partnership->revenue_share_bps)];
    }

    /** @return array{revenue_cents: int, share_cents: int} this calendar month in DC time */
    public function thisMonth(Partnership $partnership): array
    {
        $start = Carbon::now(DatabaseSeeder::TIMEZONE)->startOfMonth();

        return $this->between($partnership, $start, $start->copy()->addMonth());
    }

    /** @return array{revenue_cents: int, share_cents: int} since the partnership started */
    public function sinceStart(Partnership $partnership): array
    {
        $start = Carbon::parse($partnership->starts_on->toDateString(), DatabaseSeeder::TIMEZONE)->startOfDay();
        $end = $partnership->ends_on ? Carbon::parse($partnership->ends_on->toDateString(), DatabaseSeeder::TIMEZONE)->addDay()->startOfDay() : null;

        return $this->between($partnership, $start, $end);
    }

    /** Integer cents, half up; a negative revenue (more given back than kept) shares nothing. */
    public static function shareOf(int $revenueCents, int $bps): int
    {
        return $revenueCents <= 0 ? 0 : intdiv($revenueCents * $bps + 5000, 10000);
    }

    /** @return Builder<CreditLedgerEntry> */
    private function platformLines(int $districtId): Builder
    {
        return CreditLedgerEntry::query()->where('platform', true)->where(fn (Builder $q) => $q
            ->whereHas('redemption.merchant', fn ($m) => $m->where('district_id', $districtId))
            ->orWhereHas('creditPurchase.employer', fn ($e) => $e->where('district_id', $districtId)));
    }
}
