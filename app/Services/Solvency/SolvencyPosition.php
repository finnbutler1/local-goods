<?php

namespace App\Services\Solvency;

/**
 * Where the money is at one moment (ADR 0033), in integer cents.
 *
 * Money in hand is the money in the customer funds account (ADR 0034) =
 * paid in (credits and platform fees) − paid out to merchants + pulled
 * back from merchants − refunded to employers − fees swept to Local Goods
 * Operating. Swept money is Local Goods' own and nobody is promised it.
 * Promised = customer funds held + operating funds, each account counted
 * only above zero, so one account below zero never hides another's
 * promise. The rule: money in hand − promised ≥ 0. We expect exactly 0;
 * more means a promise is missing from the books. Separately, Stripe money
 * out never exceeds Stripe money in, so simulated money never pays for a
 * real test-mode transfer.
 */
final readonly class SolvencyPosition
{
    /**
     * @param  list<string>  $negativeAccounts  accounts below zero, as "merchant 3"
     */
    public function __construct(
        public int $employerCents,
        public int $employeeCents,
        public int $merchantCents,
        public int $heldRedemptionsCents,
        public int $heldRedemptions,
        public int $refundHoldsCents,
        public int $refundHolds,
        public int $operatingCents,
        public int $creditsPaidInCents,
        public int $feesPaidInCents,
        public int $paidOutCents,
        public int $pulledBackCents,
        public int $refundedCents,
        public int $stripeInCents,
        public int $stripeOutCents,
        public array $negativeAccounts = [],
        public int $negativeCents = 0,
        public int $sweptCents = 0,
    ) {}

    public function customerFundsCents(): int
    {
        return $this->employerCents + $this->employeeCents + $this->merchantCents + $this->heldRedemptionsCents + $this->refundHoldsCents;
    }

    public function heldPendingCents(): int
    {
        return $this->heldRedemptionsCents + $this->refundHoldsCents;
    }

    public function promisedCents(): int
    {
        return $this->customerFundsCents() + $this->operatingCents;
    }

    public function inHandCents(): int
    {
        return $this->creditsPaidInCents + $this->feesPaidInCents - $this->paidOutCents + $this->pulledBackCents - $this->refundedCents - $this->sweptCents;
    }

    /** How much more is promised than is in hand; 0 when solvent. */
    public function shortfallCents(): int
    {
        return max(0, $this->promisedCents() - $this->inHandCents());
    }

    /** How much more is in hand than is promised; 0 when every cent is accounted for. */
    public function surplusCents(): int
    {
        return max(0, $this->inHandCents() - $this->promisedCents());
    }

    public function isSolvent(): bool
    {
        return $this->shortfallCents() === 0;
    }

    public function isBalanced(): bool
    {
        return $this->inHandCents() === $this->promisedCents();
    }

    public function stripeFloorHolds(): bool
    {
        return $this->stripeOutCents <= $this->stripeInCents;
    }

    /** How far Stripe money out is above Stripe money in; 0 when the floor holds. */
    public function stripeShortfallCents(): int
    {
        return max(0, $this->stripeOutCents - $this->stripeInCents);
    }

    public function passes(): bool
    {
        return $this->isSolvent() && $this->isBalanced() && $this->stripeFloorHolds();
    }

    /** One plain sentence for a log, a halt reason or a refusal. */
    public function problem(): ?string
    {
        $money = fn (int $cents) => '$'.number_format($cents / 100, 2);
        $below = $this->negativeAccounts === [] ? '' : ' ('.implode(', ', array_slice($this->negativeAccounts, 0, 3)).' below zero by '.$money(-$this->negativeCents).')';

        return match (true) {
            ! $this->isSolvent() => 'short by '.$money($this->shortfallCents()).$below,
            ! $this->stripeFloorHolds() => 'Stripe money out is above Stripe money in by '.$money($this->stripeShortfallCents()),
            ! $this->isBalanced() => $money($this->surplusCents()).' in hand is not matched by any promise',
            default => null,
        };
    }

    /** @return array<string, int|list<string>> */
    public function toArray(): array
    {
        return get_object_vars($this) + [
            'customer_funds_cents' => $this->customerFundsCents(),
            'promised_cents' => $this->promisedCents(),
            'in_hand_cents' => $this->inHandCents(),
            'shortfall_cents' => $this->shortfallCents(),
            'surplus_cents' => $this->surplusCents(),
        ];
    }
}
