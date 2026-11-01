<?php

namespace Tests\Feature;

use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\OperatingSweep;
use App\Services\Solvency\SolvencyCheck;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * Sweeps in the solvency rule (ADR 0034). Money in hand is the money in the
 * customer funds account: a sweep of earned fees takes it out and takes the
 * same amount off the platform account, so the rule still balances to the
 * cent. Sweeping more than was earned leaves a shortfall; a Stripe sweep is
 * Stripe money out; the cross-check matches sweeps to their lines. With no
 * sweeps nothing changes (the seed tests cover that).
 */
class SolvencySweepTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOnAFixedDate;
    use UsesFakeStripe;

    private const EARNED = 160;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_seed_has_no_sweeps_and_still_balances(): void
    {
        $now = app(SolvencyCheck::class)->now();

        $this->assertSame(0, $now->sweptCents);
        $this->assertTrue($now->passes());
    }

    public function test_sweeping_earned_fees_still_balances_and_nothing_is_promised_for_the_swept_money(): void
    {
        $this->earnFees(self::EARNED);
        $before = app(SolvencyCheck::class)->now();

        $this->sweep(self::EARNED, OperatingSweep::CHANNEL_SIMULATED, OperatingSweep::STATUS_SIMULATED);
        $after = app(SolvencyCheck::class)->now();

        $this->assertTrue($after->passes(), (string) $after->problem());
        $this->assertSame(self::EARNED, $after->sweptCents);
        $this->assertSame($before->inHandCents() - self::EARNED, $after->inHandCents(), 'The swept money left the customer funds account.');
        $this->assertSame($before->operatingCents - self::EARNED, $after->operatingCents);
        $this->assertSame($before->customerFundsCents(), $after->customerFundsCents(), 'Customer funds are untouched.');
        $this->assertSame(0, $after->stripeOutCents - $before->stripeOutCents, 'A simulated sweep is not Stripe money.');

        $history = app(SolvencyCheck::class)->history();
        $this->assertTrue($history->final->passes());
        $this->assertSame([], $history->windows);
        $this->assertSame([], $history->mismatches);
        $this->assertSame($after->toArray(), $history->final->toArray(), 'The replay ends where the cheap check is.');
    }

    public function test_sweeping_more_than_was_earned_leaves_customer_funds_short(): void
    {
        $this->earnFees(self::EARNED);

        $this->sweep(self::EARNED + 25, OperatingSweep::CHANNEL_SIMULATED, OperatingSweep::STATUS_SIMULATED);
        $now = app(SolvencyCheck::class)->now();

        $this->assertSame(25, $now->shortfallCents(), 'The platform account below zero promises nothing, so the extra is a shortfall in customer funds.');
        $this->assertContains('platform', $now->negativeAccounts);
        $this->assertFalse(app(SolvencyCheck::class)->history()->final->passes());
    }

    public function test_a_stripe_sweep_is_stripe_money_out_from_the_moment_it_is_reserved(): void
    {
        $this->earnFees(self::EARNED);
        $before = app(SolvencyCheck::class)->now();

        $this->sweep(self::EARNED, OperatingSweep::CHANNEL_STRIPE, OperatingSweep::STATUS_PENDING);
        $now = app(SolvencyCheck::class)->now();

        $this->assertSame($before->stripeOutCents + self::EARNED, $now->stripeOutCents);
        $this->assertSame($now->toArray(), app(SolvencyCheck::class)->history()->final->toArray());
    }

    public function test_a_refused_sweep_comes_back_and_nets_to_nothing(): void
    {
        $this->earnFees(self::EARNED);
        $before = app(SolvencyCheck::class)->now();

        $sweep = $this->sweep(self::EARNED, OperatingSweep::CHANNEL_STRIPE, OperatingSweep::STATUS_PENDING);
        $sweep->update(['status' => OperatingSweep::STATUS_FAILED, 'open_key' => null, 'failure_reason' => 'Example refusal']);
        CreditLedgerEntry::create(['platform' => true, 'amount_cents' => self::EARNED, 'type' => CreditLedgerEntry::TYPE_OPERATING_SWEEP_RETURNED,
            'operating_sweep_id' => $sweep->id, 'description' => 'Sweep refused by Stripe']);
        $now = app(SolvencyCheck::class)->now();

        $this->assertSame(0, $now->sweptCents);
        $this->assertSame($before->toArray(), $now->toArray());
        $this->assertSame([], app(SolvencyCheck::class)->recordsAgree());
    }

    public function test_the_cross_check_finds_a_sweep_with_no_ledger_line(): void
    {
        OperatingSweep::create(['amount_cents' => 16, 'channel' => OperatingSweep::CHANNEL_SIMULATED, 'status' => OperatingSweep::STATUS_SIMULATED,
            'reason' => 'Example reason', 'reviewed_against_cents' => 16]);

        $this->assertSame(['Sweeps to Local Goods Operating come to $0.16 but their ledger lines to $0.00.'], app(SolvencyCheck::class)->recordsAgree());
    }

    /** Fees Local Goods earned, the way a paid platform fee arrives: money in, on the platform account. */
    private function earnFees(int $cents): void
    {
        $purchase = CreditPurchase::whereIn('status', CreditPurchase::PAID_STATUSES)->orderBy('id')->firstOrFail();
        $purchase->forceFill(['platform_fee_cents' => $purchase->platform_fee_cents + $cents])->saveQuietly();
        CreditLedgerEntry::create(['platform' => true, 'amount_cents' => $cents, 'type' => CreditLedgerEntry::TYPE_PLATFORM_FEE,
            'credit_purchase_id' => $purchase->id, 'description' => 'Example platform fee']);
    }

    private function sweep(int $cents, string $channel, string $status): OperatingSweep
    {
        $sweep = OperatingSweep::create(['amount_cents' => $cents, 'channel' => $channel, 'status' => $status,
            'open_key' => $status === OperatingSweep::STATUS_PENDING ? OperatingSweep::OPEN_KEY : null,
            'reason' => 'Example reason', 'reviewed_against_cents' => $cents]);
        CreditLedgerEntry::create(['platform' => true, 'amount_cents' => -$cents, 'type' => CreditLedgerEntry::TYPE_OPERATING_SWEEP,
            'operating_sweep_id' => $sweep->id, 'description' => 'Fees swept to Local Goods Operating']);

        return $sweep;
    }
}
