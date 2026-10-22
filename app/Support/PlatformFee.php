<?php

namespace App\Support;

/**
 * The platform fee setting (ADR 0021): a percent of the credits, paid on
 * top of them, kept on its own ledger line on the platform account. Off at
 * 0, the default: then no fee is charged and nothing mentions fees. The fee
 * is fixed on the purchase when it is requested, so changing the setting
 * never changes a purchase already on its way.
 */
class PlatformFee
{
    public const MAX_PERCENT = 20;

    /** Why the setting is unusable, in plain English, or null when it is fine (unset and blank are fine: 0). */
    public static function problem(): ?string
    {
        $raw = self::raw();

        if ($raw === '') {
            return null;
        }

        if (! preg_match('/^\d{1,2}(\.\d{1,2})?$/', $raw) || (float) $raw > self::MAX_PERCENT) {
            return 'PLATFORM_FEE_PERCENT must be a number from 0 to '.self::MAX_PERCENT.' (for example 2.5), or left unset for no fee.';
        }

        return null;
    }

    /** The fee in hundredths of a percent (2.5% is 250); 0 when unset or unusable. */
    public static function basisPoints(): int
    {
        return self::problem() === null && self::raw() !== '' ? Money::toCents(self::raw()) : 0;
    }

    public static function enabled(): bool
    {
        return self::basisPoints() > 0;
    }

    /** "2.5%" */
    public static function label(): string
    {
        return rtrim(rtrim(Money::toDollars(self::basisPoints()), '0'), '.').'%';
    }

    /** The fee on this many cents of credits, rounded half up to a cent. Integer arithmetic only. */
    public static function centsFor(int $creditCents): int
    {
        return intdiv($creditCents * self::basisPoints() + 5000, 10000);
    }

    private static function raw(): string
    {
        return trim((string) config('payments.platform_fee_percent'));
    }
}
