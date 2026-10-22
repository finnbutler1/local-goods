<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employer;
use App\Services\CreditLedger;
use App\Services\EmployerProgram;
use App\Services\Payments\StripeCheckout;
use App\Services\Payments\StripeReconciliation;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesFakeStripe;
use Tests\Support\AccessibilityAudit;
use Tests\TestCase;

/**
 * "Buy credits" in one step with Stripe's embedded Checkout (ADR 0015, 0021),
 * end to end against the fake Stripe: amount → card form on the same page →
 * "Confirming with Stripe…" → the signed webhook writes the ledger once →
 * green confirmation → reconciliation matches. Also cancel and try again,
 * with the platform fee off (the default) and on.
 */
class BuyCreditsFlowTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeStripe;

    private Employer $employer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeStripe();
        $this->employer = Employer::firstOrFail();
        $this->signIn(Role::EmployerAdmin);
    }

    private function buy(string $dollars = '400'): CreditPurchase
    {
        $this->post('/employer/purchases', ['purchase_amount' => $dollars]);

        return CreditPurchase::latest('id')->firstOrFail();
    }

    /** The PaymentIntent Stripe makes when the employer pays in that Checkout. */
    private function payIn(array $session): array
    {
        $this->stripe->completeSession(array_key_last($this->stripe->sessions));
        $amount = array_sum(array_map(fn ($item) => $item['price_data']['unit_amount'] * $item['quantity'], $session['line_items']));

        return [
            'id' => 'pi_test_buy', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => $amount, 'amount_received' => $amount,
            'currency' => 'usd', 'latest_charge' => 'ch_test_buy', 'transfer_group' => $session['payment_intent_data']['transfer_group'],
            'metadata' => $session['payment_intent_data']['metadata'],
        ];
    }

    /** Visible text only, so a fee mention can't hide in markup and "coffee" doesn't count. */
    private function mentionsFees(TestResponse $response): bool
    {
        return (bool) preg_match('/\bfees?\b/i', strip_tags($response->getContent()));
    }

    public static function fees(): array
    {
        return ['fee off (the default)' => [null], 'fee at 2.5%' => ['2.5']];
    }

    #[DataProvider('fees')]
    public function test_the_whole_flow_writes_the_ledger_once_and_reconciliation_matches(?string $fee): void
    {
        config(['payments.platform_fee_percent' => $fee]);
        [$credits, $feeCents] = [40000, $fee === null ? 0 : 1000];
        $balance = $this->employer->balanceCents();
        $entries = CreditLedgerEntry::count();

        // 1. Amount, then Buy credits: straight to the card form for this purchase.
        $this->get('/employer')->assertOk()->assertSee('Buy credits');
        $this->post('/employer/purchases', ['purchase_amount' => '400'])
            ->assertRedirect(route('employer.dashboard', ['pay' => ($purchase = CreditPurchase::latest('id')->firstOrFail())->reference]).'#buy');
        $this->assertTrue($purchase->isPending());
        $this->assertSame($feeCents, $purchase->platform_fee_cents);

        // 2. Paying, on the same page. With the fee on, the breakdown comes first.
        $paying = $this->get('/employer?pay='.$purchase->reference)->assertOk()->assertSee('data-embedded-checkout', false);
        $fee === null
            ? $this->assertFalse($this->mentionsFees($paying), 'With the fee off nothing mentions fees')
            : $paying->assertSee('Credits $400.00 + platform fee $10.00 = total $410.00');

        $this->postJson("/employer/purchases/{$purchase->id}/checkout")->assertOk()->assertJsonStructure(['client_secret']);
        [$session] = $this->stripe->callsOf('checkout');
        $this->assertCount($fee === null ? 1 : 2, $session['line_items']);
        $this->assertSame('embedded_page', $session['ui_mode']);
        $this->assertArrayHasKey('lg_env', $session['payment_intent_data']['metadata'], 'Environment label on the PaymentIntent');
        $this->assertArrayHasKey('lg_instance', $session['metadata'], 'Environment label on the Checkout Session');
        $this->assertSame($entries, CreditLedgerEntry::count(), 'Opening Checkout writes nothing');
        $this->getJson("/employer/purchases/{$purchase->id}/status")->assertOk()->assertJson(['status' => 'pending']);

        // 3. Back from Stripe before the webhook: "Confirming with Stripe…", checking by itself, no toast yet.
        $pi = $this->payIn($session);
        $this->assertSame($credits + $feeCents, $pi['amount']);
        $returned = $this->get('/employer?checkout=returned&purchase='.$purchase->reference)->assertOk()
            ->assertSee('Confirming with Stripe…')
            ->assertSee('data-purchase-status-url="'.route('employer.purchases.status', $purchase).'"', false)
            ->assertDontSee('data-toast="status"', false);
        $this->assertSame([], AccessibilityAudit::problems($returned->getContent()));
        $this->assertSame($balance, $this->employer->balanceCents(), 'Still locked');

        // 4. The signed webhook: the only thing that writes the ledger, once.
        $event = $this->stripeEvent('payment_intent.succeeded', $pi, id: 'evt_buy');
        $this->postWebhook($event)->assertOk()->assertJson(['outcome' => 'paid: '.$purchase->reference]);
        $this->postWebhook($event)->assertOk()->assertJson(['outcome' => 'duplicate: already handled, nothing changed']);
        $this->assertSame($balance + $credits, $this->employer->balanceCents());
        $this->assertSame(1, CreditLedgerEntry::where('credit_purchase_id', $purchase->id)->where('type', CreditLedgerEntry::TYPE_PURCHASE)->count());
        $this->assertSame($feeCents, (int) CreditLedgerEntry::where('platform', true)->sum('amount_cents'));
        $this->assertSame($fee === null ? 0 : 1, CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_PLATFORM_FEE)->count());

        // 5. The status check sees it, and the reload shows the green confirmation.
        $this->getJson("/employer/purchases/{$purchase->id}/status")->assertOk()->assertJson(['status' => 'paid']);
        $paid = $this->get('/employer?checkout=returned&purchase='.$purchase->reference)->assertOk()->assertDontSee('Confirming with Stripe…');
        $toast = html_entity_decode($paid->getContent(), ENT_QUOTES);
        $this->assertStringContainsString('data-toast="status"', $toast);
        $this->assertStringContainsString(
            '$400.00 in credits added (Stripe test mode, '.$purchase->reference.($fee === null ? '' : '; $410.00 charged, including a $10.00 platform fee').'). Ready to give.',
            $toast,
        );
        if ($fee === null) {
            $this->assertFalse($this->mentionsFees($paid));
        }

        // 6. Reconciliation: Stripe received exactly what the ledger counts.
        $this->stripe->paymentIntents = [$pi];
        $this->stripe->balance = ['available' => [['amount' => $credits + $feeCents, 'currency' => 'usd']], 'pending' => []];
        $this->assertSame([], app(StripeReconciliation::class)->report()['mismatches']);

        $this->signIn(Role::PlatformAdmin);
        $page = $this->get('/admin/reconciliation')->assertOk()->assertSee('data-reconciled="yes"', false)->assertSee(Money::format($credits + $feeCents));
        $fee === null
            ? $this->assertFalse($this->mentionsFees($page))
            : $page->assertSee('credits $400.00 + platform fees $10.00');
    }

    public function test_reconciliation_catches_a_fee_line_that_is_missing(): void
    {
        config(['payments.platform_fee_percent' => '2.5']);
        $purchase = $this->buy();
        $this->postJson("/employer/purchases/{$purchase->id}/checkout");
        $pi = $this->payIn($this->stripe->callsOf('checkout')[0]);
        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $pi))->assertOk();
        $this->stripe->paymentIntents = [$pi];

        // As if the fee had been charged but its ledger line were lost.
        $purchase->forceFill(['platform_fee_cents' => 2000])->save();

        $this->assertContains(
            'Platform fees: Stripe-paid purchases charged $20.00 in platform fees, but the platform account in the ledger has $10.00 for them.',
            app(StripeReconciliation::class)->report()['mismatches'],
        );
    }

    public function test_the_paying_state_passes_the_accessibility_audit(): void
    {
        $purchase = $this->buy();

        $this->assertSame([], AccessibilityAudit::problems($this->get('/employer?pay='.$purchase->reference)->assertOk()->getContent()));
        $this->assertSame([], AccessibilityAudit::problems($this->get('/employer?cancelled=X')->assertOk()->getContent()));
    }

    public function test_cancelling_expires_the_checkout_and_unlocks_nothing(): void
    {
        $purchase = $this->buy();
        $this->postJson("/employer/purchases/{$purchase->id}/checkout");
        $pending = EmployerProgram::pendingCents($this->employer);
        $entries = CreditLedgerEntry::count();

        $this->post("/employer/purchases/{$purchase->id}/cancel")
            ->assertRedirect(route('employer.dashboard', ['cancelled' => $purchase->reference]).'#buy')
            ->assertSessionHas('notice');

        $this->assertSame('expired', $this->stripe->sessions['cs_test_0001']['status']);
        $this->assertTrue($purchase->fresh()->isCancelled());
        $this->assertSame($pending - 40000, EmployerProgram::pendingCents($this->employer));
        $this->assertSame($entries, CreditLedgerEntry::count());

        // The form comes back filled in, with Try again; the row says Cancelled.
        $page = $this->get('/employer?cancelled='.$purchase->reference)->assertOk()
            ->assertSee('Payment cancelled.')
            ->assertSee('value="400.00"', false)
            ->assertSee('Try again')
            ->assertSee('Cancelled');
        $this->assertSame([], AccessibilityAudit::problems($page->getContent()));

        // A late payment for it is never credited (reconciliation flags it instead).
        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $this->payIn($this->stripe->callsOf('checkout')[0])))
            ->assertOk()->assertJsonPath('outcome', fn ($o) => str_starts_with($o, 'refused: '.$purchase->reference.' was cancelled'));
        $this->assertSame($entries, CreditLedgerEntry::count());
        $this->assertTrue($purchase->fresh()->isCancelled());
    }

    public function test_a_completed_checkout_cannot_be_cancelled(): void
    {
        $purchase = $this->buy();
        $this->postJson("/employer/purchases/{$purchase->id}/checkout");
        $this->stripe->completeSession('cs_test_0001');

        $this->post("/employer/purchases/{$purchase->id}/cancel")
            ->assertRedirect(route('employer.dashboard', ['checkout' => 'returned', 'purchase' => $purchase->reference]).'#buy')
            ->assertSessionHas('notice', "Stripe already has the payment for {$purchase->reference}, so it can't be cancelled. The credits arrive once Stripe confirms it.");

        $this->assertTrue($purchase->fresh()->isPending());
        $this->assertSame([], $this->stripe->callsOf('checkout.expire'));
    }

    public function test_when_stripe_cannot_be_asked_cancelling_changes_nothing(): void
    {
        $purchase = $this->buy();
        $this->postJson("/employer/purchases/{$purchase->id}/checkout");
        $this->stripe->sessionFailure = 'Stripe could not be reached.';

        $this->post("/employer/purchases/{$purchase->id}/cancel")->assertSessionHas('error');

        $this->assertTrue($purchase->fresh()->isPending());
        $this->assertSame('open', $this->stripe->sessions['cs_test_0001']['status']);
    }

    public function test_try_again_closes_the_old_checkout_before_opening_a_new_one(): void
    {
        $purchase = $this->buy();
        $this->postJson("/employer/purchases/{$purchase->id}/checkout");
        $oldToken = $purchase->fresh()->payment_token;

        $this->post("/employer/purchases/{$purchase->id}/pay")->assertRedirect(route('employer.dashboard', ['pay' => $purchase->reference]).'#buy');

        $this->assertSame('expired', $this->stripe->sessions['cs_test_0001']['status']);
        $this->assertNotSame($oldToken, $purchase->fresh()->payment_token);
        $this->postJson("/employer/purchases/{$purchase->id}/checkout")->assertOk()->assertJson(['client_secret' => 'cs_test_0002_secret_fake']);
        $this->assertSame('lg-checkout-'.$purchase->fresh()->payment_token, last($this->stripe->idempotencyKeys));
    }

    public function test_try_again_after_paying_waits_for_stripe_instead(): void
    {
        $purchase = $this->buy();
        $this->postJson("/employer/purchases/{$purchase->id}/checkout");
        $token = $purchase->fresh()->payment_token;
        $this->stripe->completeSession('cs_test_0001');

        $this->post("/employer/purchases/{$purchase->id}/pay")
            ->assertRedirect(route('employer.dashboard', ['checkout' => 'returned', 'purchase' => $purchase->reference]).'#buy');

        $this->assertSame($token, $purchase->fresh()->payment_token, 'The token the payment carries is kept');
    }

    public function test_a_cancelled_purchase_cannot_be_paid_or_opened(): void
    {
        $purchase = $this->buy();
        $this->post("/employer/purchases/{$purchase->id}/cancel");

        $this->post("/employer/purchases/{$purchase->id}/pay")->assertSessionHas('error', "Nothing changed: {$purchase->reference} is cancelled.");
        $this->postJson("/employer/purchases/{$purchase->id}/checkout")->assertStatus(422)->assertJson(['message' => "Payment didn't start: That purchase was cancelled. Nothing was charged."]);
        $this->get('/employer?pay='.$purchase->reference)->assertOk()->assertDontSee('data-embedded-checkout', false);
    }

    public function test_the_return_url_is_app_url_even_when_the_request_came_to_beanstalk(): void
    {
        $this->app['env'] = 'production';
        config(['app.url' => 'https://d111example.cloudfront.net']);
        $this->app->instance('request', Request::create('http://local-goods.example.elasticbeanstalk.com/employer/purchases/1/checkout', 'POST'));
        app('url')->setRequest(app('request'));
        $purchase = app(CreditLedger::class)->requestPurchase($this->employer, 10000, 'MPG-APPURL-1');

        app(StripeCheckout::class)->start($purchase);

        [$session] = $this->stripe->callsOf('checkout');
        $this->assertSame('https://d111example.cloudfront.net/employer?checkout=returned&purchase=MPG-APPURL-1#buy', $session['return_url']);
        $this->assertStringNotContainsString('elasticbeanstalk', json_encode($session));
    }

    public function test_another_employers_purchase_is_not_found(): void
    {
        $other = Employer::create(['bid_id' => $this->employer->bid_id, 'name' => 'Other Co', 'monthly_allowance_cents' => 1000, 'staff_count' => 5]);
        $purchase = app(CreditLedger::class)->requestPurchase($other, 10000, 'OC-1');

        $this->postJson("/employer/purchases/{$purchase->id}/checkout")->assertNotFound();
        $this->getJson("/employer/purchases/{$purchase->id}/status")->assertNotFound();
        $this->post("/employer/purchases/{$purchase->id}/cancel")->assertNotFound();
        $this->assertSame([], $this->stripe->calls);
    }
}
