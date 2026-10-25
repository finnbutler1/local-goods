<?php

namespace App\Support;

use App\Models\MerchantFeeChange;
use App\Models\Redemption;
use App\Models\User;

/**
 * The merchant transaction fee (ADR 0029): a percent in basis points plus an
 * optional fixed amount per sale, kept by Local Goods out of what the
 * merchant is transferred. 0 and 0 by default (no setting row), and stays
 * so until customer discovery. The fee is snapshotted on each redemption
 * when it is made, so changing the setting never changes an existing one.
 * Separate from the employer-side PlatformFee (ADR 0021).
 */
final class MerchantFee
{
    /** 10% */
    public const MAX_BPS = 1000;

    /** $1.00 */
    public const MAX_FIXED_CENTS = 100;

    public function __construct(public readonly int $bps, public readonly int $fixedCents, public readonly int $changeId = 0) {}

    /** The newest setting, or none (0 and 0) while nobody has set one. */
    public static function current(): self
    {
        $change = MerchantFeeChange::orderByDesc('id')->first();

        return $change ? new self($change->fee_bps, $change->fixed_cents, $change->id) : new self(0, 0);
    }

    /** The fee snapshotted on a redemption. */
    public static function of(Redemption $redemption): self
    {
        return new self((int) $redemption->merchant_fee_bps, (int) $redemption->merchant_fee_fixed_cents);
    }

    public function isNone(): bool
    {
        return $this->bps === 0 && $this->fixedCents === 0;
    }

    /**
     * The fee on a sale of this many cents (the total, tax included): the
     * percent rounded half up to a cent, plus the fixed part, never more
     * than the sale. Integer arithmetic only.
     */
    public function centsOn(int $totalCents): int
    {
        return max(0, min($totalCents, intdiv($totalCents * $this->bps + 5000, 10000) + $this->fixedCents));
    }

    /** The columns snapshotted onto a redemption of this total. @return array<string, int> */
    public function columns(int $totalCents): array
    {
        return ['merchant_fee_bps' => $this->bps, 'merchant_fee_fixed_cents' => $this->fixedCents, 'merchant_fee_cents' => $this->centsOn($totalCents)];
    }

    /** "none right now", "2.5% per sale", "$0.10 per sale" or "2.5% + $0.10 per sale". */
    public function label(): string
    {
        if ($this->isNone()) {
            return 'none right now';
        }

        $parts = array_filter([
            $this->bps > 0 ? self::percent($this->bps) : null,
            $this->fixedCents > 0 ? Money::format($this->fixedCents) : null,
        ]);

        return implode(' + ', $parts).' per sale';
    }

    /**
     * For judges (read-only), who count as the public: no fee figures
     * (PublicClaimsTest), only whether there is a fee at all.
     */
    public function wordsLabel(): string
    {
        return $this->isNone() ? 'none right now' : 'on (amounts are shown to Local Goods and BID staff)';
    }

    /** Fee amounts and rates are shown to staff, merchants and employers; never to judges, who count as the public. */
    public static function showsFiguresTo(?User $user): bool
    {
        return $user !== null && ! $user->isReadOnly();
    }

    /** 250 → "2.5%" */
    public static function percent(int $bps): string
    {
        return rtrim(rtrim(Money::toDollars($bps), '0'), '.').'%';
    }

    /**
     * How much of a sale's fee has gone back after refunds adding up to
     * $refundedCents of a $totalCents sale whose fee was $feeCents: the same
     * share, rounded half up, and the whole fee once all of it is refunded.
     * Never decreases and never passes the fee, so refunds worked out
     * cumulatively (this minus the share before) return exactly the fee
     * over all of them, never more.
     */
    public static function returnedAfter(int $feeCents, int $totalCents, int $refundedCents): int
    {
        if ($feeCents <= 0 || $refundedCents <= 0) {
            return 0;
        }

        if ($refundedCents >= $totalCents) {
            return $feeCents;
        }

        return min($feeCents, intdiv(2 * $feeCents * $refundedCents + $totalCents, 2 * $totalCents));
    }
}
