<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employer;
use App\Services\CreditLedger;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * Employer purchases in Stripe test mode (ADR 0015): "Buy credits" opens
 * Stripe's embedded Checkout on the same page and the purchase stays
 * pending, with no ledger entry, until a verified webhook confirms payment
 * (StripeWebhookTest). The whole flow end to end is in BuyCreditsFlowTest.
 */
class StripeCheckoutTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeStripe;

    /**
     * "fee" or "fees" not touching another letter or digit: catches "platform
     * fee" and "platform_fee_cents", never the hex inside an ID like 3fee0b1c-….
     */
    private const FEE_WORD = '/(?<![a-z0-9])fees?(?![a-z0-9])/i';

    private CreditPurchase $purchase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeStripe();
        $this->signIn(Role::EmployerAdmin);

        $this->post('/employer/purchases', ['purchase_amount' => '250'])->assertRedirect();
        $this->purchase = CreditPurchase::latest('id')->firstOrFail();
    }

    private function openCheckout(): array
    {
        $this->postJson("/employer/purchases/{$this->purchase->id}/checkout")->assertOk()->assertJson(['client_secret' => 'cs_test_0001_secret_fake']);

        return $this->stripe->callsOf('checkout')[0];
    }

    public function test_buy_credits_goes_straight_to_the_card_form_for_that_purchase(): void
    {
        $this->post('/employer/purchases', ['purchase_amount' => '300'])
            ->assertRedirect(route('employer.dashboard', ['pay' => CreditPurchase::latest('id')->value('reference')]).'#buy');

        $this->get('/employer')->assertOk()
            ->assertSee('Stripe test mode')
            ->assertSee('>Buy credits<', false)
            ->assertDontSee('Request purchase')
            ->assertDontSee('Pay now (simulated, no real money)');
    }

    public function test_the_paying_state_shows_the_test_card_and_stripes_form(): void
    {
        $this->get('/employer?pay='.$this->purchase->reference)->assertOk()
            ->assertSee('Paying for <span class="font-semibold">'.$this->purchase->reference.'</span>', false)
            ->assertSee('Test mode:</strong> use card <span class="tabular font-mono">4000 0000 0000 0077</span>, any future date, any CVC.', false)
            ->assertSee('data-embedded-checkout', false)
            ->assertSee('data-secret-url="'.route('employer.purchases.checkout', $this->purchase).'"', false)
            ->assertSee('https://js.stripe.com/endive/stripe.js', false)
            ->assertSee('Cancel payment');

        $this->assertSame([], $this->stripe->calls, 'Showing the page creates nothing at Stripe');
    }

    public function test_opening_the_form_makes_an_embedded_checkout_and_stays_pending(): void
    {
        $entries = CreditLedgerEntry::count();

        $session = $this->openCheckout();

        $this->assertSame('embedded_page', $session['ui_mode']);
        $this->assertSame($this->purchase->reference, $session['payment_intent_data']['transfer_group']);
        $this->assertSame(25000, $session['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('usd', $session['line_items'][0]['price_data']['currency']);
        $this->assertSame($this->purchase->fresh()->payment_token, $session['payment_intent_data']['metadata']['lg_purchase_token']);

        $this->assertTrue($this->purchase->fresh()->isPending(), 'Pending until the webhook');
        $this->assertSame('cs_test_0001', $this->purchase->fresh()->stripe_checkout_session_id);
        $this->assertSame($entries, CreditLedgerEntry::count(), 'No ledger entry before the webhook');
    }

    public function test_pending_stripe_credits_cannot_be_allocated(): void
    {
        $this->openCheckout();
        $balance = Employer::firstOrFail()->balanceCents();

        $this->post('/employer/allocations', ['mode' => 'bulk', 'recipients' => 'all', 'occasion' => 'monthly', 'amount' => (string) ($balance / 100 + 1)])
            ->assertSessionHasErrors('amount');
    }

    public function test_opening_the_form_twice_reuses_the_same_checkout_session(): void
    {
        $this->openCheckout();
        $this->openCheckout();

        $this->assertCount(1, $this->stripe->callsOf('checkout'));
        $this->assertSame(['lg-checkout-'.$this->purchase->fresh()->payment_token], array_values(array_unique($this->stripe->idempotencyKeys)));
    }

    public function test_judges_see_the_stripe_badge_and_payment_id_but_no_pay_button(): void
    {
        $this->openCheckout();
        app(CreditLedger::class)->markPurchasePaidByStripe($this->purchase->fresh(), 'pi_test_judge', 'ch_test_judge', 25000, 'usd');
        $pending = app(CreditLedger::class)->requestPurchase(Employer::firstOrFail(), 1000, 'MPG-PENDING');

        $this->signIn(Role::Judge);
        $this->get('/judge/view-as/employer_admin');

        $this->get('/employer')->assertOk()
            ->assertSee('Stripe test mode')
            ->assertSee('pi_test_judge')
            ->assertSee("Read-only: judges can't pay", false)
            ->assertDontSee('/pay"', false);
        // Even with ?pay= in the address a judge gets no card form.
        $this->get('/employer?pay='.$pending->reference)->assertOk()->assertDontSee('data-embedded-checkout', false);
        $this->postJson("/employer/purchases/{$pending->id}/checkout")->assertForbidden();
        $this->assertCount(1, $this->stripe->callsOf('checkout'), 'A judge opened Checkout');
    }

    /**
     * The live bug of 2026-10-16: Stripe's current API refuses
     * payment_method_types on a Checkout Session (400). Every field we send
     * must be one Stripe accepts, and the amount, currency, return URL and
     * transfer group must be right.
     */
    public function test_the_checkout_request_only_sends_fields_stripe_accepts(): void
    {
        $session = $this->openCheckout();

        $this->assertArrayNotHasKey('payment_method_types', $session);
        $this->assertSame(
            ['mode', 'ui_mode', 'client_reference_id', 'line_items', 'payment_intent_data', 'metadata', 'expires_at', 'return_url'],
            array_keys($session),
        );
        $this->assertSame('payment', $session['mode']);
        $this->assertCount(1, $session['line_items'], 'With the fee off: credits only');
        $this->assertSame(['currency' => 'usd', 'unit_amount' => 25000], array_intersect_key($session['line_items'][0]['price_data'], array_flip(['currency', 'unit_amount'])));
        $this->assertSame($this->purchase->reference, $session['payment_intent_data']['transfer_group']);
        $this->assertSame(route('employer.dashboard').'?checkout=returned&purchase='.$this->purchase->reference.'#buy', $session['return_url']);
        $this->assertEqualsWithDelta(now()->addHour()->getTimestamp(), $session['expires_at'], 5);
        // Whole words only: the metadata carries a random UUID token, and hex can spell "fee".
        $this->assertDoesNotMatchRegularExpression(self::FEE_WORD, json_encode($session));
        $this->assertDoesNotMatchRegularExpression(self::FEE_WORD, $session['line_items'][0]['price_data']['product_data']['name']);
    }

    public function test_a_payment_token_that_happens_to_contain_fee_does_not_fail_the_fee_check(): void
    {
        // payment_token is a random UUID (hex), so about 1 run in 140 it contains "fee".
        // Forcing one here keeps the "no fee text" check from ever failing by chance.
        Str::createUuidsUsing(fn () => Uuid::fromString('3fee0b1c-0fee-4a2b-8c3d-9e8f7a6b5fee'));

        try {
            $this->test_the_checkout_request_only_sends_fields_stripe_accepts();
            $this->assertStringContainsString('fee', $this->purchase->fresh()->payment_token);
        } finally {
            Str::createUuidsNormally();
        }
    }

    public function test_the_fee_word_check_still_catches_a_fee(): void
    {
        $this->assertMatchesRegularExpression(self::FEE_WORD, '{"name":"Local Goods platform fee (MPG-20261023-1)"}');
        $this->assertMatchesRegularExpression(self::FEE_WORD, 'Fees: $5.00');
        $this->assertMatchesRegularExpression(self::FEE_WORD, '{"platform_fee_cents":500}');
        $this->assertDoesNotMatchRegularExpression(self::FEE_WORD, '{"lg_purchase_token":"3fee0b1c-0fee-4a2b-8c3d-9e8f7a6b5fee"}');
    }

    public function test_when_stripe_refuses_checkout_the_reason_is_shown_with_try_again(): void
    {
        $this->stripe->checkoutFailure = 'Stripe refused the request (InvalidRequestException, field return_url).';

        $this->postJson("/employer/purchases/{$this->purchase->id}/checkout")->assertStatus(422)
            ->assertJson(['message' => "Payment didn't start: Stripe refused the request (InvalidRequestException, field return_url). Nothing was charged."]);

        // The page has the panel checkout.js fills in, with a Try again button.
        $this->get('/employer?pay='.$this->purchase->reference)->assertOk()
            ->assertSee('data-checkout-error', false)
            ->assertSee('action="'.route('employer.purchases.pay', $this->purchase).'"', false)
            ->assertSee('Try again');
        $this->assertTrue($this->purchase->fresh()->isPending());
    }
}
