<?php

namespace Tests\Unit;

use App\Support\MerchantFee;
use PHPUnit\Framework\TestCase;

/** The merchant fee's arithmetic (ADR 0029): integer cents, half up, never more than the sale. */
class MerchantFeeTest extends TestCase
{
    public function test_percent_rounds_half_up_to_a_cent(): void
    {
        $fee = new MerchantFee(250, 0);

        $this->assertSame(16, $fee->centsOn(633));   // 15.825
        $this->assertSame(15, $fee->centsOn(600));   // 15.000
        $this->assertSame(1, $fee->centsOn(20));     // 0.5 rounds up
        $this->assertSame(0, $fee->centsOn(19));     // 0.475
    }

    public function test_fixed_part_adds_on(): void
    {
        $this->assertSame(10, (new MerchantFee(0, 10))->centsOn(633));
        $this->assertSame(26, (new MerchantFee(250, 10))->centsOn(633));
    }

    public function test_never_more_than_the_sale(): void
    {
        $max = new MerchantFee(MerchantFee::MAX_BPS, MerchantFee::MAX_FIXED_CENTS);

        $this->assertSame(1, $max->centsOn(1));
        $this->assertSame(50, $max->centsOn(50));
        $this->assertSame(111, $max->centsOn(111)); // 11.1 → 11, + 100: the whole sale
        $this->assertSame(111, $max->centsOn(112)); // 11.2 → 11, + 100
        $this->assertSame(200, $max->centsOn(1000));
    }

    public function test_none_charges_nothing(): void
    {
        $none = new MerchantFee(0, 0);

        $this->assertTrue($none->isNone());
        $this->assertSame(0, $none->centsOn(123456));
        $this->assertSame('none right now', $none->label());
    }

    public function test_labels(): void
    {
        $this->assertSame('2.5% per sale', (new MerchantFee(250, 0))->label());
        $this->assertSame('$0.10 per sale', (new MerchantFee(0, 10))->label());
        $this->assertSame('2.5% + $0.10 per sale', (new MerchantFee(250, 10))->label());
        $this->assertSame('10% + $1.00 per sale', (new MerchantFee(1000, 100))->label());
        $this->assertSame('0.05%', MerchantFee::percent(5));
    }

    public function test_snapshot_columns(): void
    {
        $this->assertSame(['merchant_fee_bps' => 250, 'merchant_fee_fixed_cents' => 10, 'merchant_fee_cents' => 26], (new MerchantFee(250, 10))->columns(633));
    }

    public function test_refund_share_is_cumulative_and_returns_exactly_the_fee(): void
    {
        // The worked example: 16¢ fee on $6.33, refunded $2.11 then $4.22.
        $this->assertSame(5, MerchantFee::returnedAfter(16, 633, 211));
        $this->assertSame(16, MerchantFee::returnedAfter(16, 633, 633));
        $this->assertSame(0, MerchantFee::returnedAfter(0, 633, 633));
        $this->assertSame(0, MerchantFee::returnedAfter(16, 633, 0));
    }

    public function test_any_sequence_of_partial_refunds_returns_the_fee_once_and_never_more_than_each_refund(): void
    {
        mt_srand(29);
        for ($run = 0; $run < 500; $run++) {
            $total = mt_rand(1, 5000);
            $fee = (new MerchantFee(mt_rand(0, MerchantFee::MAX_BPS), mt_rand(0, MerchantFee::MAX_FIXED_CENTS)))->centsOn($total);
            $refunded = 0;
            $returned = 0;

            while ($refunded < $total) {
                $part = mt_rand(1, $total - $refunded);
                $share = MerchantFee::returnedAfter($fee, $total, $refunded + $part) - MerchantFee::returnedAfter($fee, $total, $refunded);
                $this->assertGreaterThanOrEqual(0, $share);
                $this->assertLessThanOrEqual($part, $share);
                $refunded += $part;
                $returned += $share;
                $this->assertLessThanOrEqual($fee, $returned);
            }

            $this->assertSame($fee, $returned);
        }
    }
}
