<?php

namespace App\Support;

use App\Models\District;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The BID annual program fee (ADR 0044): a district pays once a program
 * year, from its program start date, at the fee of its size tier. Off
 * unless BID_PROGRAM_FEE_ENABLED is exactly true; it charges only in Stripe
 * test mode. Every sentence about whether it is on comes from status().
 *
 * The price is only ever the tier's fee in config/projections.php
 * bid_fee_tiers, chosen by the district's member-business count, which a
 * platform admin sets (DistrictProgramSettings). Nothing a browser sends
 * decides it.
 */
class BidProgramFee
{
    /** A sanity ceiling on a tier's annual fee; anything above it stops a deploy. */
    public const MAX_FEE_CENTS = 2_500_000;

    public static function enabled(): bool
    {
        return config('payments.bid_program_fee_enabled') === true;
    }

    /** Why the tiers are unusable, in plain English, or null when they are fine. Off is always fine. */
    public static function problem(): ?string
    {
        if (! self::enabled()) {
            return null;
        }

        $tiers = config('projections.bid_fee_tiers');
        if (! is_array($tiers) || $tiers === []) {
            return 'BID_PROGRAM_FEE_ENABLED is on, but config/projections.php has no bid_fee_tiers.';
        }

        $previous = -1;
        foreach ($tiers as $i => $tier) {
            $min = $tier['min_businesses'] ?? null;
            $fee = $tier['fee'] ?? null;
            if (! is_int($min) || ! (is_int($fee) || is_float($fee)) || ($i === 0 && $min !== 0) || $min <= $previous
                || $fee * 100 < 1 || $fee * 100 > self::MAX_FEE_CENTS || ! is_string($tier['name'] ?? null)) {
                return 'BID_PROGRAM_FEE_ENABLED is on, but bid_fee_tiers in config/projections.php is not a list of tiers starting at 0 member businesses, '
                    .'each with a name, a higher min_businesses and a fee from $0.01 to '.Money::format(self::MAX_FEE_CENTS).'.';
            }
            $previous = $min;
        }

        return null;
    }

    /** On, with usable tiers, in Stripe test mode: the one test every charging path asks. */
    public static function charges(): bool
    {
        return self::enabled() && self::problem() === null && StripeMode::active();
    }

    /**
     * What pages say about the fee right now, to finish "The BID program
     * fee is …". Never claims more than the setting does.
     */
    public static function status(): string
    {
        return match (true) {
            self::charges() => 'charged in Stripe test mode: no real money',
            self::enabled() && self::problem() === null => 'switched on, but it charges only in Stripe test mode, which is not set up here, so nothing is charged',
            default => 'planned, not charged in the app',
        };
    }

    /** @return array{name: string, min_businesses: int, fee_cents: int, businesses: string} the tier for this many member businesses */
    public static function tierFor(int $memberBusinesses): array
    {
        $chosen = null;
        foreach (config('projections.bid_fee_tiers') as $tier) {
            if ($memberBusinesses >= $tier['min_businesses']) {
                $chosen = $tier;
            }
        }

        return [
            'name' => $chosen['name'],
            'min_businesses' => $chosen['min_businesses'],
            'fee_cents' => (int) round($chosen['fee'] * 100),
            'businesses' => $chosen['businesses'],
        ];
    }

    /** True once a platform admin has set both the member count and the program start date. */
    public static function isSetUp(District $district): bool
    {
        return $district->member_businesses !== null && $district->program_starts_on !== null;
    }

    /**
     * The program year that is running on this day, counted from the
     * district's start date in DC time (year 1 starts on the start date), or
     * null before the program starts or while the district isn't set up.
     *
     * @return array{year: int, starts_on: CarbonImmutable, ends_on: CarbonImmutable}|null
     */
    public static function programYear(District $district, ?CarbonInterface $at = null): ?array
    {
        if (! self::isSetUp($district)) {
            return null;
        }

        $tz = config('fraud.timezone');
        $today = CarbonImmutable::instance($at ?? now())->setTimezone($tz)->startOfDay();
        $start = CarbonImmutable::parse($district->program_starts_on->format('Y-m-d'), $tz);

        if ($today->lt($start)) {
            return null;
        }

        $year = (int) $start->diffInYears($today) + 1;
        $starts = $start->addYearsNoOverflow($year - 1);

        return ['year' => $year, 'starts_on' => $starts, 'ends_on' => $starts->addYearNoOverflow()->subDay()];
    }

    /** The payment's unique key while it is pending or paid: one per district per program year. */
    public static function key(District $district, int $programYear): string
    {
        return $district->id.':'.$programYear;
    }
}
