<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Services\Sandbox\Sandbox;
use App\Services\Sandbox\SandboxCheckout;
use App\Services\Sandbox\SandboxCheckoutClient;
use App\Services\Sandbox\SandboxStripe;
use App\Support\ProductionConfig;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\UsesFakeStripe;
use Tests\Concerns\UsesSandboxes;
use Tests\Fakes\FakeSandboxCheckoutClient;
use Tests\TestCase;

/**
 * Stripe's embedded card form for Buy credits inside a sandbox (ADR 0045
 * addendum), on the separate sandbox Stripe test account, with a fake
 * client. Same page and JS as the live purchase; the live keys are set and a
 * fake live gateway is bound throughout, so any use of the live account, or
 * a key crossing sides, would show. Every test watches the live database.
 */
class SandboxStripeEmbeddedCheckoutTest extends TestCase
{
    use RefreshDatabase, UsesFakeStripe, UsesSandboxes;

    private const SANDBOX_SECRET = 'sk_test_fake_sandbox_account_not_a_real_key';

    // Must not contain the live fake key ('pk_test_fake'), so "never on the other side" can be checked by text.
    private const SANDBOX_PUBLISHABLE = 'pk_test_sandbox_account_example_not_a_real_key';

    private const LIVE_PUBLISHABLE = 'pk_test_fake';

    private FakeSandboxCheckoutClient $checkout;

    /** @var list<string> */
    private array $mainWrites = [];

    private bool $watching = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSandboxes();
        $this->fakeStripe();
        config(['sandbox.stripe.enabled' => true, 'sandbox.stripe.secret' => self::SANDBOX_SECRET, 'sandbox.stripe.publishable' => self::SANDBOX_PUBLISHABLE]);
        $this->checkout = new FakeSandboxCheckoutClient;
        $this->app->instance(SandboxCheckoutClient::class, $this->checkout);

        DB::listen(function (QueryExecuted $query) {
            if ($this->watching && $query->connectionName !== Sandbox::CONNECTION && ! preg_match('/^\s*select\b/i', $query->sql)) {
                $this->mainWrites[] = "[{$query->connectionName}] {$query->sql}";
            }
        });
    }

    private function asSandboxEmployer(): void
    {
        $this->sandboxJar = [];
        $this->startSandbox();
        $this->switchRole('employer');
        $this->watching = true;
    }

    private function buy(string $amount = '250'): TestResponse
    {
        return $this->sandboxPost(route('employer.purchases.store'), ['purchase_amount' => $amount]);
    }

    private function sandboxPurchase(): CreditPurchase
    {
        return $this->insideSandbox(fn () => CreditPurchase::orderByDesc('id')->firstOrFail());
    }

    private function purchaseLines(CreditPurchase $purchase): int
    {
        return $this->insideSandbox(fn () => CreditLedgerEntry::where('credit_purchase_id', $purchase->id)->where('type', CreditLedgerEntry::TYPE_PURCHASE)->count());
    }

    private function payingPage(CreditPurchase $purchase): TestResponse
    {
        return $this->sandboxGet(route('employer.dashboard', ['pay' => $purchase->reference]));
    }

    private function assertNoLiveStripeOrWrites(): void
    {
        $this->assertSame([], $this->stripe->calls, 'The live Stripe account was used');
        $this->assertSame([], $this->mainWrites, 'A sandbox request wrote to the live database');
    }

    public function test_buy_credits_opens_the_embedded_card_form_with_the_sandbox_key_and_session(): void
    {
        $this->asSandboxEmployer();

        $this->sandboxGet(route('employer.dashboard'))->assertOk()
            ->assertSee('pay by card right here', false)
            ->assertSee('Stripe test mode (separate sandbox). '.SandboxStripe::TEST_CARD_HINT, false);

        $this->buy('250')->assertRedirect(route('employer.dashboard', ['pay' => $this->sandboxPurchase()->reference]).'#buy');
        $purchase = $this->sandboxPurchase();
        $id = $this->checkout->latestId();
        $this->assertSame($id, $purchase->stripe_checkout_session_id);
        $this->assertSame(1, $this->checkout->count('create'));

        $params = $this->checkout->sessions[$id]['params'];
        $this->assertSame('embedded_page', $params['ui_mode']);
        $this->assertStringContainsString('/sandbox-return?session_id={CHECKOUT_SESSION_ID}', $params['return_url']);
        $this->assertArrayNotHasKey('success_url', $params);
        $this->assertArrayNotHasKey('cancel_url', $params);
        $this->assertSame(SandboxCheckout::CHECKOUT_EMAIL, $params['customer_email']);
        $this->assertSame(25000, $params['line_items'][0]['price_data']['unit_amount']);
        $tag = $this->insideSandbox(fn () => SandboxStripe::tag());
        $this->assertSame(['lg_sandbox' => $tag, 'lg_purchase_token' => $purchase->payment_token, 'lg_purchase_reference' => $purchase->reference], $params['metadata']);

        // The same card form as the live page, with the sandbox account's key.
        $page = $this->payingPage($purchase)->assertOk()
            ->assertSee('data-embedded-checkout', false)
            ->assertSee('data-stripe-key="'.self::SANDBOX_PUBLISHABLE.'"', false)
            ->assertSee('https://js.stripe.com/endive/stripe.js', false)
            ->assertSee(SandboxStripe::TEST_CARD_HINT, false)
            ->assertSee('data-checkout-fallback', false)
            ->assertDontSee('4000 0000 0000 0077', false);
        $this->assertStringNotContainsString(self::LIVE_PUBLISHABLE.'"', $page->getContent(), 'The live publishable key reached a sandbox page');
        $this->assertStringNotContainsString('sk_test_', $page->getContent());

        $this->assertSame(0, $this->purchaseLines($purchase), 'Nothing is recorded before Stripe confirms');
        $this->assertNoLiveStripeOrWrites();
    }

    public function test_the_client_secret_comes_only_from_the_saved_sandbox_session_and_never_opens_a_new_one(): void
    {
        $this->asSandboxEmployer();
        $this->buy('250');
        $purchase = $this->sandboxPurchase();
        $id = $this->checkout->latestId();

        $this->sandboxPostJson(route('employer.purchases.checkout', $purchase))->assertOk()
            ->assertExactJson(['client_secret' => $this->checkout->sessions[$id]['client_secret']]);
        // A reload asks again: same session, nothing new opened or counted.
        $this->sandboxPostJson(route('employer.purchases.checkout', $purchase))->assertOk();
        $this->payingPage($purchase)->assertOk();
        $this->assertSame(1, $this->checkout->count('create'));
        $this->assertSame(9, $this->insideSandbox(fn () => SandboxCheckout::checkoutsLeft()));

        // Expired at Stripe: a message (Try again), still nothing opened.
        $this->checkout->sessions[$id]['status'] = 'expired';
        $this->sandboxPostJson(route('employer.purchases.checkout', $purchase))->assertStatus(422)
            ->assertJson(['message' => "Payment didn't start: This payment form has closed. Press Try again for a new one. No new charge was made."]);
        $this->assertSame(1, $this->checkout->count('create'));

        // Try again: a fresh embedded session, back on the card form.
        $this->sandboxPost(route('employer.purchases.pay', $purchase))->assertRedirect(route('employer.dashboard', ['pay' => $purchase->reference]).'#buy');
        $this->assertSame(2, $this->checkout->count('create'));
        $this->sandboxPostJson(route('employer.purchases.checkout', $purchase))->assertOk()
            ->assertExactJson(['client_secret' => $this->checkout->sessions[$this->checkout->latestId()]['client_secret']]);

        // Stripe down: a message, never a 500.
        $this->checkout->down = true;
        $this->sandboxPostJson(route('employer.purchases.checkout', $purchase))->assertStatus(422);
        $this->checkout->down = false;
        $this->assertNoLiveStripeOrWrites();
    }

    public function test_paying_on_the_card_form_is_recorded_on_return_once_and_only_when_everything_matches(): void
    {
        $this->asSandboxEmployer();
        $this->buy('250');
        $purchase = $this->sandboxPurchase();
        $id = $this->checkout->latestId();
        $this->checkout->pay($id);
        $return = fn (string $session) => $this->sandboxGet(route('employer.purchases.sandbox-return', $purchase).'?session_id='.$session);

        foreach ([
            'amount' => ['amount_total' => $purchase->totalCents() - 1],
            'currency' => ['currency' => 'eur'],
            'sandbox' => ['metadata' => ['lg_sandbox' => str_repeat('0', 32)] + $this->checkout->sessions[$id]['metadata']],
            'token' => ['metadata' => ['lg_purchase_token' => 'fake-token'] + $this->checkout->sessions[$id]['metadata']],
            'reference' => ['metadata' => ['lg_purchase_reference' => 'MER-9999'] + $this->checkout->sessions[$id]['metadata']],
            'unpaid' => ['payment_status' => 'unpaid'],
        ] as $what => $override) {
            $real = $this->checkout->sessions[$id];
            $this->checkout->sessions[$id] = array_replace($real, $override);
            $return($id);
            $this->assertSame(0, $this->purchaseLines($purchase), "Recorded with a wrong {$what}");
            $this->checkout->sessions[$id] = $real;
        }
        $return('cs_test_forged_fake')->assertSessionHas('error');
        $this->assertSame(0, $this->purchaseLines($purchase));

        $return($id)->assertSessionHas('status', fn ($s) => str_contains($s, '$250.00 in credits added (Stripe test mode, separate sandbox account, '.$purchase->reference));
        $return($id)->assertSessionHas('status', fn ($s) => str_contains($s, 'already paid'));
        $this->assertSame(1, $this->purchaseLines($purchase));
        $this->assertSame(CreditPurchase::STATUS_PAID, $this->sandboxPurchase()->status);

        // Paid: the card form doesn't open again.
        $this->payingPage($purchase)->assertOk()->assertDontSee('data-embedded-checkout', false);
        $this->sandboxPostJson(route('employer.purchases.checkout', $purchase))->assertStatus(422);
        $this->assertNoLiveStripeOrWrites();
    }

    public function test_cancel_expires_the_embedded_session(): void
    {
        $this->asSandboxEmployer();
        $this->buy('250');
        $purchase = $this->sandboxPurchase();
        $id = $this->checkout->latestId();

        $this->sandboxPost(route('employer.purchases.cancel', $purchase))->assertRedirect();
        $this->assertSame('expired', $this->checkout->sessions[$id]['status']);
        $this->assertTrue($this->sandboxPurchase()->isCancelled());
        $this->assertNoLiveStripeOrWrites();
    }

    public function test_falls_back_to_hosted_when_the_publishable_key_is_missing_or_not_a_test_publishable_key(): void
    {
        foreach (['missing' => null, 'empty' => '', 'live' => 'pk_live_fake_not_a_real_key', 'secret' => 'sk_test_fake_not_a_real_key',
            'prefix only' => 'pk_test_', 'restricted' => 'rk_test_fake_not_a_real_key'] as $case => $key) {
            config(['sandbox.stripe.publishable' => $key]);
            $this->asSandboxEmployer();
            $this->assertFalse($this->insideSandbox(fn () => SandboxStripe::embeddedAvailable()), $case);

            $this->sandboxGet(route('employer.dashboard'))->assertOk()->assertSee("pay on Stripe's page", false);
            $this->buy('250')->assertRedirect('https://checkout.stripe.com/c/pay/'.$this->checkout->latestId());
            $purchase = $this->sandboxPurchase();
            $this->assertArrayNotHasKey('ui_mode', $this->checkout->sessions[$this->checkout->latestId()]['params'], $case);

            // No card form, and its JSON is a 404, with this key.
            $this->payingPage($purchase)->assertOk()->assertDontSee('data-embedded-checkout', false);
            $this->sandboxPostJson(route('employer.purchases.checkout', $purchase))->assertNotFound();
        }
        $this->assertNoLiveStripeOrWrites();
    }

    public function test_if_the_card_form_fails_to_load_the_fallback_opens_hosted_checkout_and_closes_the_embedded_one(): void
    {
        $this->asSandboxEmployer();
        $this->buy('250');
        $purchase = $this->sandboxPurchase();
        $embedded = $this->checkout->latestId();

        // What checkout.js submits when Stripe.js doesn't load or can't mount (and the noscript button).
        $this->payingPage($purchase)->assertSee('name="hosted" value="1"', false)->assertSee("Pay on Stripe's page instead", false);
        $this->sandboxPost(route('employer.purchases.pay', $purchase), ['hosted' => '1'])
            ->assertRedirect('https://checkout.stripe.com/c/pay/'.($hosted = $this->checkout->latestId()));
        $this->assertNotSame($embedded, $hosted);
        $this->assertSame('expired', $this->checkout->sessions[$embedded]['status'], 'Only one Checkout can take the payment');
        $this->assertSame($hosted, $this->sandboxPurchase()->stripe_checkout_session_id);
        $this->assertStringContainsString('/sandbox-return', $this->checkout->sessions[$hosted]['params']['success_url']);

        // Going back to Stripe's page again reuses it.
        $this->sandboxPost(route('employer.purchases.pay', $purchase), ['hosted' => '1'])->assertRedirect('https://checkout.stripe.com/c/pay/'.$hosted);
        $this->assertSame(2, $this->checkout->count('create'));

        // Recorded the same way after paying there.
        $this->checkout->pay($hosted);
        $this->sandboxGet(route('employer.purchases.sandbox-return', $purchase).'?session_id='.$hosted)->assertSessionHas('status');
        $this->assertSame(1, $this->purchaseLines($purchase));
        $this->assertNoLiveStripeOrWrites();
    }

    public function test_then_simulated_when_stripe_is_off_down_or_the_limit_is_used(): void
    {
        // Off, or a bad secret: simulated even with a good publishable key.
        foreach (['off' => ['sandbox.stripe.enabled' => false], 'bad secret' => ['sandbox.stripe.secret' => 'sk_live_fake_not_a_real_key']] as $case => $settings) {
            config(['sandbox.stripe.enabled' => true, 'sandbox.stripe.secret' => self::SANDBOX_SECRET]);
            config($settings);
            $this->asSandboxEmployer();
            $this->sandboxGet(route('employer.dashboard'))->assertOk()->assertSee('data-sandbox-simulated', false)->assertDontSee(self::SANDBOX_PUBLISHABLE, false);
            $this->buy('250')->assertSessionHas('status', fn ($s) => str_contains($s, 'simulated, no real money'));
            $this->assertSame(CreditPurchase::STATUS_SIMULATED, $this->sandboxPurchase()->status, $case);
        }
        $this->assertSame([], $this->checkout->calls);
        config(['sandbox.stripe.enabled' => true, 'sandbox.stripe.secret' => self::SANDBOX_SECRET]);

        // Stripe down.
        $this->checkout->down = true;
        $this->asSandboxEmployer();
        $this->buy('250')->assertSessionHas('status', fn ($s) => str_contains($s, 'Stripe could not be reached, so this payment was simulated'));
        $this->assertSame(CreditPurchase::STATUS_SIMULATED, $this->sandboxPurchase()->status);
        $this->checkout->down = false;

        // Ten Checkouts, then simulated; the $5,000 cap is checked before anything.
        $this->asSandboxEmployer();
        $this->buy('5000.01')->assertSessionHasErrors('purchase_amount');
        for ($i = 0; $i < 10; $i++) {
            $this->buy('10')->assertRedirect(route('employer.dashboard', ['pay' => $this->sandboxPurchase()->reference]).'#buy');
        }
        $this->buy('10')->assertSessionHas('status', fn ($s) => str_contains($s, 'opened its 10 Stripe Checkouts, so this payment was simulated'));
        $this->assertSame(CreditPurchase::STATUS_SIMULATED, $this->sandboxPurchase()->status);
        $this->assertNoLiveStripeOrWrites();
    }

    public function test_the_live_page_keeps_the_live_publishable_key_and_never_gets_the_sandbox_one(): void
    {
        $this->assertNull(SandboxStripe::publishableKey(), 'Outside a sandbox there is no sandbox key');
        $this->seed(DatabaseSeeder::class);
        $this->signIn(Role::EmployerAdmin);

        $this->get(route('employer.dashboard'))->assertOk()->assertDontSee(self::SANDBOX_PUBLISHABLE, false);
        $this->post(route('employer.purchases.store'), ['purchase_amount' => '100'])->assertRedirect();
        $live = CreditPurchase::orderByDesc('id')->firstOrFail();

        $page = $this->get(route('employer.dashboard', ['pay' => $live->reference]))->assertOk()
            ->assertSee('data-stripe-key="'.self::LIVE_PUBLISHABLE.'"', false)
            ->assertSee('4000 0000 0000 0077', false)
            ->assertDontSee('data-checkout-fallback', false)
            ->assertDontSee(SandboxStripe::TEST_CARD_HINT, false);
        $this->assertStringNotContainsString(self::SANDBOX_PUBLISHABLE, $page->getContent());

        // The live client secret is from the live account, with no made-up email, and the sandbox client is never used.
        $this->postJson(route('employer.purchases.checkout', $live))->assertOk();
        $created = array_values(array_filter($this->stripe->calls, fn ($c) => $c[0] === 'checkout'));
        $this->assertCount(1, $created);
        $this->assertArrayNotHasKey('customer_email', $created[0][1]);
        $this->assertSame([], $this->checkout->calls, 'The sandbox client was used on the live site');
    }

    public function test_the_deploy_check_refuses_the_live_publishable_key_as_the_sandbox_one(): void
    {
        config(['sandbox.stripe.publishable' => self::LIVE_PUBLISHABLE]);
        $problem = SandboxStripe::publishableProblem();
        $this->assertNotNull($problem);
        $this->assertContains($problem, ProductionConfig::problems());
        $this->assertStringNotContainsString(self::LIVE_PUBLISHABLE, $problem, 'The message never prints a key');

        config(['sandbox.stripe.enabled' => false]);
        $this->assertNull(SandboxStripe::publishableProblem());
        config(['sandbox.stripe.enabled' => true, 'sandbox.stripe.publishable' => self::SANDBOX_PUBLISHABLE]);
        $this->assertNull(SandboxStripe::publishableProblem());
        $this->assertNotContains($problem, ProductionConfig::problems());
    }

    public function test_the_sandbox_card_form_page_has_the_same_security_policy_as_the_live_one(): void
    {
        $this->asSandboxEmployer();
        $this->buy('250');
        $sandbox = $this->payingPage($this->sandboxPurchase())->assertOk()->headers->get('Content-Security-Policy-Report-Only');
        $this->watching = false;

        $this->seed(DatabaseSeeder::class);
        $this->signIn(Role::EmployerAdmin);
        $this->post(route('employer.purchases.store'), ['purchase_amount' => '100']);
        $live = $this->get(route('employer.dashboard', ['pay' => CreditPurchase::orderByDesc('id')->firstOrFail()->reference]))
            ->assertOk()->headers->get('Content-Security-Policy-Report-Only');

        $this->assertNotNull($sandbox);
        $this->assertSame($live, $sandbox);
        foreach (['script-src' => 'https://js.stripe.com', 'frame-src' => 'https://checkout.stripe.com', 'connect-src' => 'https://api.stripe.com'] as $directive => $origin) {
            $this->assertMatchesRegularExpression('/'.$directive.' [^;]*'.preg_quote($origin, '/').'/', $sandbox);
        }
    }
}
