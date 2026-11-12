<?php

namespace App\Support;

use App\Models\Employer;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The employer platform fee (ADR 0042): a monthly fee per participating
 * employee, added once a month to the employer's first credit purchase.
 * Built and tested; switched off during the pilot. Off unless
 * EMPLOYER_PLATFORM_FEE_ENABLED is exactly true.
 *
 * The price is the base employer_platform_fee in config/projections.php
 * (the business model's own figure), never a number written here. It is
 * copied onto the purchase when the purchase is requested, so a later
 * change to the projection never changes a purchase already made.
 *
 * Separate from the percent PlatformFee (ADR 0021): the two are never on
 * together.
 */
class EmployerPlatformFee
{
    /** A sanity ceiling on the per-employee price; anything above it stops a deploy. */
    public const MAX_UNIT_CENTS = 5000;

    public const ASSUMPTION = 'employer_platform_fee';

    public static function enabled(): bool
    {
        return config('payments.employer_platform_fee_enabled') === true;
    }

    /** Why the setting is unusable, in plain English, or null when it is fine. Off is always fine. */
    public static function problem(): ?string
    {
        if (! self::enabled()) {
            return null;
        }

        if (PlatformFee::basisPoints() > 0 || PlatformFee::problem() !== null) {
            return 'EMPLOYER_PLATFORM_FEE_ENABLED and PLATFORM_FEE_PERCENT can\'t both be on: an employer would see two platform fees. Turn one off.';
        }

        $cents = self::rawUnitCents();
        if ($cents === null || $cents <= 0 || $cents > self::MAX_UNIT_CENTS) {
            return 'EMPLOYER_PLATFORM_FEE_ENABLED is on, but the base employer_platform_fee in config/projections.php is not a price from $0.01 to '
                .Money::format(self::MAX_UNIT_CENTS).' per employee.';
        }

        return null;
    }

    /** True only when it is on and its setting is usable: the one test every charging path asks. */
    public static function charges(): bool
    {
        return self::enabled() && self::problem() === null;
    }

    /** The price per participating employee per month, in cents (the projection's base case). */
    public static function unitCents(): int
    {
        return max(0, (int) self::rawUnitCents());
    }

    /** The calendar month a purchase at this moment pays the fee for, in DC time: "2026-11". */
    public static function monthOf(CarbonInterface $at): string
    {
        return Carbon::instance($at)->setTimezone(config('fraud.timezone'))->format('Y-m');
    }

    /** "November 2026" for "2026-11". */
    public static function monthLabel(string $month): string
    {
        return Carbon::createFromFormat('!Y-m', $month, config('fraud.timezone'))->format('F Y');
    }

    /** The purchase's unique key while it carries (or may still pay) this month's fee: one per employer per month. */
    public static function key(Employer $employer, string $month): string
    {
        return $employer->id.':'.$month;
    }

    private static function rawUnitCents(): ?int
    {
        $base = config('projections.assumptions.'.self::ASSUMPTION.'.base');

        return is_int($base) || is_float($base) ? (int) round($base * 100) : null;
    }
}
