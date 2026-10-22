<?php

namespace Tests\Feature;

use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employer;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Services\CreditLedger;
use App\Support\PlatformFee;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * The platform fee (ADR 0021): off at 0, the default, and then the ledger
 * is exactly as before. When on, it is fixed on the purchase, paid on top of
 * the credits, and written as its own line on the platform account, never on
 * the employer's balance.
 */
class PlatformFeeTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeStripe;

    private Employer $employer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->employer = Employer::firstOrFail();
    }

    private function ledger(): CreditLedger
    {
        return app(CreditLedger::class);
    }

    private function feeLines(): int
    {
        return CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_PLATFORM_FEE)->count();
    }

    public function test_the_fee_is_off_by_default(): void
    {
        $this->assertNull(config('payments.platform_fee_percent'));
        $this->assertFalse(PlatformFee::enabled());
        $this->assertSame(0, PlatformFee::centsFor(1000000));
    }

    public function test_the_fee_rounds_half_up_to_a_cent(): void
    {
        config(['payments.platform_fee_percent' => '2.5']);

        $this->assertSame(250, PlatformFee::centsFor(10000));
        $this->assertSame(250, PlatformFee::centsFor(10001), '250.025 cents');
        $this->assertSame(251, PlatformFee::centsFor(10020), '250.5 cents');
        $this->assertSame('2.5%', PlatformFee::label());
    }

    public function test_a_bad_setting_charges_no_fee(): void
    {
        config(['payments.platform_fee_percent' => '25']);

        $this->assertNotNull(PlatformFee::problem());
        $this->assertSame(0, PlatformFee::centsFor(10000));
    }

    public function test_with_the_fee_off_a_purchase_writes_no_fee_line(): void
    {
        $balance = $this->employer->balanceCents();

        $purchase = $this->ledger()->requestPurchase($this->employer, 50000, 'MPG-FEE-0');
        $this->ledger()->payPurchase($purchase);

        $this->assertSame(0, $purchase->fresh()->platform_fee_cents);
        $this->assertSame(50000, $purchase->fresh()->totalCents());
        $this->assertSame(0, $this->feeLines());
        $this->assertSame($balance + 50000, $this->employer->balanceCents());
    }

    public function test_with_the_fee_on_it_is_its_own_line_on_the_platform_account(): void
    {
        config(['payments.platform_fee_percent' => '2.5']);
        $balance = $this->employer->balanceCents();

        $purchase = $this->ledger()->requestPurchase($this->employer, 50000, 'MPG-FEE-1');
        $this->ledger()->payPurchase($purchase);

        $this->assertSame([1250, 51250], [$purchase->fresh()->platform_fee_cents, $purchase->fresh()->totalCents()]);
        $this->assertSame($balance + 50000, $this->employer->balanceCents(), 'The fee is never credit to give out');

        $fee = CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_PLATFORM_FEE)->sole();
        $this->assertSame([true, null, null, null, 1250, $purchase->id], [$fee->platform, $fee->employer_id, $fee->employee_id, $fee->merchant_id, $fee->amount_cents, $fee->credit_purchase_id]);
    }

    public function test_the_fee_is_fixed_when_the_purchase_is_requested(): void
    {
        config(['payments.platform_fee_percent' => '2.5']);
        $purchase = $this->ledger()->requestPurchase($this->employer, 10000, 'MPG-FEE-2');

        config(['payments.platform_fee_percent' => '10']);
        $this->ledger()->payPurchase($purchase);

        $this->assertSame(250, (int) CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_PLATFORM_FEE)->sum('amount_cents'));
    }

    public function test_stripe_must_have_received_the_credits_and_the_fee(): void
    {
        $this->fakeStripe();
        config(['payments.platform_fee_percent' => '2.5']);
        $purchase = $this->ledger()->requestPurchase($this->employer, 10000, 'MPG-FEE-3');
        $purchase->forceFill(['payment_token' => (string) Str::uuid()])->save();
        $balance = $this->employer->balanceCents();
        $pi = fn (int $amount, string $id) => [
            'id' => $id, 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => $amount, 'amount_received' => $amount,
            'currency' => 'usd', 'latest_charge' => 'ch_'.$id, 'metadata' => ['lg_purchase_token' => $purchase->payment_token],
        ];

        // Only the credits, not the fee: refused, nothing written.
        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $pi(10000, 'pi_short')))
            ->assertOk()->assertJsonPath('outcome', fn ($o) => str_starts_with($o, 'refused: '));
        $this->assertTrue($purchase->fresh()->isPending());
        $this->assertSame(0, $this->feeLines());

        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $pi(10250, 'pi_full')))
            ->assertOk()->assertJson(['outcome' => 'paid: MPG-FEE-3']);
        $this->assertSame($balance + 10000, $this->employer->balanceCents());
        $this->assertSame(250, (int) CreditLedgerEntry::where('platform', true)->sum('amount_cents'));
        $this->assertStringContainsString('pi_full', CreditLedgerEntry::where('platform', true)->value('description'));
    }

    public function test_the_ledger_invariant_counts_fees_as_money_in(): void
    {
        config(['payments.platform_fee_percent' => '2.5']);
        $this->ledger()->payPurchase($this->ledger()->requestPurchase($this->employer, 40000, 'MPG-FEE-4'));

        $paid = CreditPurchase::whereIn('status', CreditPurchase::PAID_STATUSES);
        $moneyIn = (int) (clone $paid)->sum('amount_cents') + (int) (clone $paid)->sum('platform_fee_cents');
        $paidOut = (int) MerchantPayout::paid()->sum('amount_cents');
        $held = (int) Redemption::where('status', Redemption::STATUS_UNDER_REVIEW)->sum('amount_cents');

        $this->assertSame($moneyIn - $paidOut - $held, (int) CreditLedgerEntry::sum('amount_cents'));
    }

    public function test_a_platform_line_belongs_to_no_other_account(): void
    {
        $this->expectException(LogicException::class);

        CreditLedgerEntry::create(['platform' => true, 'employer_id' => $this->employer->id, 'amount_cents' => 1, 'type' => CreditLedgerEntry::TYPE_PLATFORM_FEE]);
    }

    public function test_the_platform_marker_is_never_false(): void
    {
        $this->expectExceptionObject(new LogicException('The platform marker is true or empty, never false.'));

        CreditLedgerEntry::create(['platform' => false, 'amount_cents' => 1, 'type' => CreditLedgerEntry::TYPE_PLATFORM_FEE]);
    }
}
