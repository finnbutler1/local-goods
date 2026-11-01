<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CreditPurchase;
use App\Models\MerchantPayout;
use App\Services\Payments\StripeCheckout;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * Where the money is held, shown where it moves (ADR 0034): the employer's
 * Buy credits screen and receipt say their money is held in the customer
 * funds account and is never used for Local Goods' own expenses; Stripe's
 * Checkout says the same under its Pay button; each merchant payout row
 * says which account paid it. Words only, and no "fee" with the fee off.
 */
class CustomerFundsNoticeTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;
    use SeedsOnAFixedDate;
    use UsesFakeStripe;

    private const HELD = 'Your money is held in the Local Goods customer funds account (Stripe test mode) and stays yours until your staff spend it.';

    private const NEVER = 'It is never used for Local Goods\' own expenses.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_buy_credits_paying_and_confirming_all_say_where_the_money_is_held(): void
    {
        $this->fakeStripe();
        $this->signIn(Role::EmployerAdmin);

        $this->get('/employer')->assertOk()->assertSeeText(self::HELD)->assertSeeText(self::NEVER)
            ->assertSeeText('Money Local Goods earns is moved out separately, by a recorded sweep.');

        $this->post('/employer/purchases', ['purchase_amount' => '250'])->assertRedirect();
        $purchase = CreditPurchase::latest('id')->firstOrFail();
        $this->get('/employer?pay='.$purchase->reference)->assertOk()->assertSee('data-embedded-checkout', false)->assertSeeText(self::HELD);

        $this->postJson("/employer/purchases/{$purchase->id}/checkout")->assertOk();
        $this->get('/employer?checkout=returned&purchase='.$purchase->reference)->assertOk()
            ->assertSee('Confirming with Stripe…')->assertSeeText(self::HELD);
    }

    public function test_stripes_checkout_says_it_under_the_pay_button_and_on_the_payment(): void
    {
        $this->fakeStripe();
        $this->signIn(Role::EmployerAdmin);
        $this->post('/employer/purchases', ['purchase_amount' => '250'])->assertRedirect();
        $purchase = CreditPurchase::latest('id')->firstOrFail();

        $this->postJson("/employer/purchases/{$purchase->id}/checkout")->assertOk();
        [$session] = $this->stripe->callsOf('checkout');

        $this->assertSame(StripeCheckout::CUSTOMER_FUNDS_MESSAGE, $session['custom_text']['submit']['message']);
        $this->assertLessThanOrEqual(1200, mb_strlen(StripeCheckout::CUSTOMER_FUNDS_MESSAGE), "Stripe's limit for custom_text");
        $this->assertStringEndsWith('held as customer funds', $session['payment_intent_data']['description']);
        $this->assertDoesNotMatchRegularExpression('/\bfees?\b/i', StripeCheckout::CUSTOMER_FUNDS_MESSAGE);
    }

    public function test_the_receipt_rows_say_where_paid_credits_are_held(): void
    {
        $this->fakeStripe();
        $this->stripePurchase(25000);
        $this->signIn(Role::EmployerAdmin);

        $this->get('/employer')->assertOk()
            ->assertSee('data-held-in="customer-funds"', false)
            ->assertSeeText('Held in the customer funds account until your staff spend it.')
            ->assertSeeText('Recorded as customer funds until your staff spend it (simulated).');
    }

    public function test_simulated_buying_says_it_is_recorded_as_customer_funds(): void
    {
        $this->signIn(Role::EmployerAdmin);

        $this->get('/employer')->assertOk()
            ->assertSeeText('Your money is recorded as customer funds (simulated, no real money) and stays yours until your staff spend it.')
            ->assertDontSeeText(self::HELD);
    }

    public function test_each_merchant_payout_row_says_which_account_paid_it(): void
    {
        $this->fakeStripe();
        $this->stripePurchase(50000);
        $this->onboard();
        $this->cleared(1200);
        $this->assertSame(1, MerchantPayout::where('status', MerchantPayout::STATUS_SENT)->count());

        $this->signIn(Role::Merchant);
        $this->get('/merchant')->assertOk()
            ->assertSee('data-paid-from="customer-funds"', false)->assertSeeText('Paid from the Local Goods customer funds account.')
            ->assertSee('data-paid-from="simulated"', false)->assertSeeText('Simulated, from customer funds in the ledger; no money moved.');
    }
}
