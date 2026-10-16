<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employer;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * Employer purchases in Stripe test mode (ADR 0015): "Pay with Stripe"
 * opens Stripe Checkout and the purchase stays pending, with no ledger
 * entry, until a verified webhook confirms payment (StripeWebhookTest).
 */
class StripeCheckoutTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeStripe;

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

    public function test_the_page_shows_stripe_test_mode_and_the_test_card(): void
    {
        $this->get('/employer')->assertOk()
            ->assertSee('Stripe test mode')
            ->assertSee('Test mode:</strong> use card <span class="tabular font-mono">4000 0000 0000 0077</span>, any future date, any CVC.', false)
            ->assertSee('Pay with Stripe (test mode)')
            ->assertDontSee('Pay now (simulated, no real money)');
    }

    public function test_paying_opens_checkout_with_the_reference_as_transfer_group_and_stays_pending(): void
    {
        $entries = CreditLedgerEntry::count();

        $this->post("/employer/purchases/{$this->purchase->id}/pay")
            ->assertRedirect('https://checkout.stripe.test/cs_test_0001');

        [$session] = $this->stripe->callsOf('checkout');
        $this->assertSame($this->purchase->reference, $session['payment_intent_data']['transfer_group']);
        $this->assertSame(25000, $session['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('usd', $session['line_items'][0]['price_data']['currency']);
        $this->assertSame($this->purchase->fresh()->payment_token, $session['payment_intent_data']['metadata']['lg_purchase_token']);

        $this->assertTrue($this->purchase->fresh()->isPending(), 'Pending until the webhook');
        $this->assertSame('cs_test_0001', $this->purchase->fresh()->stripe_checkout_session_id);
        $this->assertSame($entries, CreditLedgerEntry::count(), 'No ledger entry before the webhook');
    }

    public function test_coming_back_from_checkout_writes_nothing_and_says_it_is_waiting(): void
    {
        $this->post("/employer/purchases/{$this->purchase->id}/pay");
        $entries = CreditLedgerEntry::count();

        $this->get('/employer?checkout=returned&purchase='.$this->purchase->reference)->assertOk()
            ->assertSee('stays pending until Stripe confirms the payment');

        $this->assertTrue($this->purchase->fresh()->isPending());
        $this->assertSame($entries, CreditLedgerEntry::count());
    }

    public function test_pending_stripe_credits_cannot_be_allocated(): void
    {
        $this->post("/employer/purchases/{$this->purchase->id}/pay");
        $balance = Employer::firstOrFail()->balanceCents();

        $this->post('/employer/allocations', ['mode' => 'bulk', 'recipients' => 'all', 'occasion' => 'monthly', 'amount' => (string) ($balance / 100 + 1)])
            ->assertSessionHasErrors('amount');
    }

    public function test_paying_twice_reuses_the_same_checkout_session(): void
    {
        $this->post("/employer/purchases/{$this->purchase->id}/pay");
        $this->post("/employer/purchases/{$this->purchase->id}/pay")->assertRedirect('https://checkout.stripe.test/cs_test_0001');

        $this->assertCount(1, $this->stripe->callsOf('checkout'));
    }
}
