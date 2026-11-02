<?php

namespace Tests\Unit;

use App\Services\Solvency\SolvencyPosition;
use PHPUnit\Framework\TestCase;

/**
 * The split of customer funds held on Where the money is: the part paid
 * through Stripe test mode and still there, and the simulated rest.
 */
class StripeBackedCustomerFundsTest extends TestCase
{
    private function position(int $customerFunds, int $stripeIn, int $stripeOut): SolvencyPosition
    {
        return new SolvencyPosition(
            employerCents: $customerFunds, employeeCents: 0, merchantCents: 0, heldRedemptionsCents: 0, heldRedemptions: 0,
            refundHoldsCents: 0, refundHolds: 0, operatingCents: 0, creditsPaidInCents: 0, feesPaidInCents: 0, paidOutCents: 0,
            pulledBackCents: 0, refundedCents: 0, stripeInCents: $stripeIn, stripeOutCents: $stripeOut,
        );
    }

    public function test_stripe_money_in_less_out_less_unswept_stripe_earnings(): void
    {
        $this->assertSame(6800, $this->position(700000, 10000, 3000)->stripeBackedCustomerFundsCents(200));
    }

    public function test_never_more_than_customer_funds_held_and_never_below_zero(): void
    {
        $this->assertSame(5000, $this->position(5000, 10000, 0)->stripeBackedCustomerFundsCents(0));
        $this->assertSame(0, $this->position(5000, 1000, 900)->stripeBackedCustomerFundsCents(500));
        $this->assertSame(0, $this->position(5000, 0, 0)->stripeBackedCustomerFundsCents(-16), 'An over-sweep never adds Stripe money');
    }
}
