<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Correction;
use App\Models\CorrectionAction;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employer;
use App\Models\User;
use App\Services\Corrections\CorrectionDecisions;
use App\Services\Corrections\CorrectionRequests;
use App\Services\Sandbox\Sandbox;
use App\Services\Sandbox\SandboxCheckout;
use App\Services\Sandbox\SandboxCheckoutClient;
use App\Services\Sandbox\SandboxRoutes;
use App\Services\Sandbox\SandboxStripe;
use App\Services\Sandbox\SandboxStripeClient;
use App\Services\Solvency\SolvencyCheck;
use App\Support\ProductionConfig;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\UsesFakeStripe;
use Tests\Concerns\UsesSandboxes;
use Tests\Fakes\FakeSandboxCheckoutClient;
use Tests\TestCase;

/**
 * Real Stripe Checkout for Buy credits inside a sandbox, on a separate
 * Stripe test account (ADR 0045 addendum), with a fake client. The live
 * Stripe keys are set and a fake live gateway is bound throughout, so any
 * use of the live account would show; every test also watches the live
 * database connection for writes.
 */
class SandboxStripeCheckoutTest extends TestCase
{
    use RefreshDatabase, UsesFakeStripe, UsesSandboxes;

    private const SANDBOX_KEY = 'sk_test_fake_sandbox_account_not_a_real_key';

    private FakeSandboxCheckoutClient $checkout;

    /** @var list<string> */
    private array $mainWrites = [];

    private bool $watching = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSandboxes();
        $this->fakeStripe();
        config(['sandbox.stripe.enabled' => true, 'sandbox.stripe.secret' => self::SANDBOX_KEY]);
        $this->checkout = new FakeSandboxCheckoutClient;
        $this->app->instance(SandboxCheckoutClient::class, $this->checkout);

        DB::listen(function (QueryExecuted $query) {
            if ($this->watching && $query->connectionName !== Sandbox::CONNECTION && ! preg_match('/^\s*select\b/i', $query->sql)) {
                $this->mainWrites[] = "[{$query->connectionName}] {$query->sql}";
            }
        });
    }

    /** Starts a sandbox as its employer and watches the live database from here on. */
    private function asSandboxEmployer(): void
    {
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

    private function returnFor(CreditPurchase $purchase, string $sessionId): TestResponse
    {
        return $this->sandboxGet(route('employer.purchases.sandbox-return', $purchase).'?session_id='.$sessionId);
    }

    private function assertNoLiveStripeOrWrites(): void
    {
        $this->assertSame([], $this->stripe->calls, 'The live Stripe account was used');
        $this->assertSame([], $this->mainWrites, 'A sandbox request wrote to the live database');
    }

    public function test_buy_credits_opens_hosted_checkout_on_the_sandbox_account_with_the_expected_lines_and_metadata(): void
    {
        $this->asSandboxEmployer();

        $page = $this->sandboxGet(route('employer.dashboard'))->assertOk();
        $page->assertSee('Stripe test mode (separate sandbox). '.SandboxStripe::TEST_CARD_HINT, false)
            ->assertSee("pay on Stripe's page", false)
            ->assertSee('Buy credits (Stripe test mode, no real money)')
            ->assertDontSee('Buy credits (simulated, no real money)')
            ->assertSee('Payments use a separate Stripe test account: no real money, and nothing reaches the live demo. Deleted after 24 hours.')
            ->assertDontSee('data-sandbox-simulated', false);

        $response = $this->buy('250');

        $id = $this->checkout->latestId();
        $response->assertRedirect('https://checkout.stripe.com/c/pay/'.$id);
        $session = $this->checkout->sessions[$id]['params'];
        $purchase = $this->sandboxPurchase();

        $this->assertTrue($purchase->isPending());
        $this->assertSame($id, $purchase->stripe_checkout_session_id);
        $this->assertSame('payment', $session['mode']);
        $this->assertArrayNotHasKey('ui_mode', $session);
        $this->assertSame(SandboxCheckout::CHECKOUT_EMAIL, $session['customer_email'], 'A made-up email, so a visitor never types their own');
        $this->assertSame('Local Goods credits ('.$purchase->reference.')', $session['line_items'][0]['price_data']['product_data']['name']);
        $this->assertSame(25000, $session['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame($purchase->totalCents(), $this->checkout->sessions[$id]['amount_total']);
        $tag = $this->insideSandbox(fn () => SandboxStripe::tag());
        $this->assertSame(['lg_sandbox' => $tag, 'lg_purchase_token' => $purchase->payment_token, 'lg_purchase_reference' => $purchase->reference], $session['metadata']);
        $this->assertStringNotContainsString((string) $this->sandboxId(), json_encode($session), 'The sandbox file name never goes to Stripe');
        $this->assertStringContainsString('session_id={CHECKOUT_SESSION_ID}', $session['success_url']);
        $this->assertStringContainsString('/sandbox-return', $session['success_url']);
        $this->assertSame(0, $this->purchaseLines($purchase), 'Nothing is recorded before Stripe confirms');
        $this->assertNoLiveStripeOrWrites();
    }

    public function test_the_employer_platform_fee_line_appears_on_checkout_when_it_applies(): void
    {
        config(['payments.employer_platform_fee_enabled' => true]);
        $this->asSandboxEmployer();

        $this->buy('100');

        $purchase = $this->sandboxPurchase();
        $this->assertTrue($purchase->hasEmployerFee(), 'The seeded sandbox had participating employees last month');
        $lines = $this->checkout->sessions[$this->checkout->latestId()]['params']['line_items'];
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('Platform fee for ', $lines[1]['price_data']['product_data']['name']);
        $this->assertSame((int) $purchase->employer_fee_cents, $lines[1]['price_data']['unit_amount']);

        $id = $this->checkout->latestId();
        $this->checkout->pay($id);
        $this->returnFor($purchase, $id)->assertRedirect();
        $this->assertTrue($this->insideSandbox(fn () => $purchase->refresh()->isStripe()));
        $this->assertSame(1, $this->insideSandbox(fn () => CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_EMPLOYER_PLATFORM_FEE)->where('credit_purchase_id', $purchase->id)->count()));
        $this->assertNoLiveStripeOrWrites();
    }

    public function test_the_return_records_the_purchase_once_and_a_refresh_records_nothing_more(): void
    {
        $this->asSandboxEmployer();
        $this->buy('250');
        $purchase = $this->sandboxPurchase();
        $id = $this->checkout->latestId();
        $this->checkout->pay($id);

        $this->returnFor($purchase, $id)->assertRedirect(route('employer.dashboard').'#credits')
            ->assertSessionHas('status', fn ($s) => str_contains($s, '$250.00 in credits added (Stripe test mode, separate sandbox account, '.$purchase->reference));

        $paid = $this->sandboxPurchase();
        $this->assertSame(CreditPurchase::STATUS_PAID, $paid->status);
        $this->assertSame('pi_test_sandbox_fake_'.$id, $paid->stripe_payment_intent_id);
        $this->assertSame(1, $this->purchaseLines($purchase));

        // Refresh, and the back button: nothing more.
        $this->returnFor($purchase, $id)->assertSessionHas('status', fn ($s) => str_contains($s, 'already paid'));
        $this->returnFor($purchase, $id);
        $this->assertSame(1, $this->purchaseLines($purchase));
        $this->assertTrue($this->insideSandbox(fn () => app(SolvencyCheck::class)->now()->isSolvent()));
        $this->assertNoLiveStripeOrWrites();
    }

    public function test_an_unpaid_session_a_forged_id_and_any_mismatch_record_nothing(): void
    {
        $this->asSandboxEmployer();
        $this->buy('250');
        $purchase = $this->sandboxPurchase();
        $id = $this->checkout->latestId();

        // Not paid yet.
        $this->returnFor($purchase, $id)->assertSessionHas('notice', fn ($s) => str_contains($s, 'nothing was recorded'));

        // Paid, but the address carries another session id (forged or someone else's).
        $this->checkout->pay($id);
        $this->returnFor($purchase, 'cs_test_forged_fake')->assertSessionHas('error', fn ($s) => str_contains($s, "didn't match"));
        $this->sandboxGet(route('employer.purchases.sandbox-return', $purchase))->assertSessionHas('error');

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
            $this->returnFor($purchase, $id);
            $this->assertSame(0, $this->purchaseLines($purchase), "Recorded with a wrong {$what}");
            $this->assertTrue($this->sandboxPurchase()->isPending(), $what);
            $this->checkout->sessions[$id] = $real;
        }

        // Another purchase's session, even a paid one, never pays this purchase.
        $this->buy('300');
        $other = $this->checkout->latestId();
        $this->checkout->pay($other);
        $this->returnFor($purchase, $other);
        $this->assertSame(0, $this->purchaseLines($purchase));

        // Stripe can't be reached: nothing recorded, still pending, then it works.
        $this->checkout->down = true;
        $this->returnFor($purchase, $id)->assertSessionHas('error', fn ($s) => str_contains($s, "Couldn't reach Stripe"));
        $this->checkout->down = false;
        $this->returnFor($purchase, $id)->assertSessionHas('status');
        $this->assertSame(1, $this->purchaseLines($purchase));
        $this->assertNoLiveStripeOrWrites();
    }

    public function test_continue_paying_reuses_the_open_checkout_and_cancel_expires_it(): void
    {
        $this->asSandboxEmployer();
        $this->buy('250');
        $purchase = $this->sandboxPurchase();
        $id = $this->checkout->latestId();

        // Left Stripe's page: a notice, and the purchase waits with Continue paying and Cancel.
        $this->sandboxGet(route('employer.dashboard', ['checkout' => 'left', 'purchase' => $purchase->reference]))
            ->assertOk()->assertSee('Payment not finished')->assertSee('Continue paying');

        $this->sandboxPost(route('employer.purchases.pay', $purchase))->assertRedirect('https://checkout.stripe.com/c/pay/'.$id);
        $this->assertSame(1, $this->checkout->count('create'));

        $this->sandboxPost(route('employer.purchases.cancel', $purchase))->assertRedirect();
        $this->assertSame('expired', $this->checkout->sessions[$id]['status']);
        $this->assertTrue($this->sandboxPurchase()->isCancelled());

        // Paid at Stripe before Cancel: recorded instead of cancelled.
        $this->buy('100');
        $second = $this->sandboxPurchase();
        $this->checkout->pay($this->checkout->latestId());
        $this->sandboxPost(route('employer.purchases.cancel', $second))->assertSessionHas('status');
        $this->assertSame(1, $this->purchaseLines($second));
        $this->assertNoLiveStripeOrWrites();
    }

    public function test_falls_back_to_simulated_when_off_the_key_is_wrong_or_stripe_is_down(): void
    {
        foreach (['off' => ['sandbox.stripe.enabled' => false], 'missing' => ['sandbox.stripe.secret' => null],
            'live key' => ['sandbox.stripe.secret' => 'sk_live_fake_not_a_real_key'], 'restricted' => ['sandbox.stripe.secret' => 'rk_test_fake_not_a_real_key'],
            'publishable' => ['sandbox.stripe.secret' => 'pk_test_fake_not_a_real_key']] as $case => $settings) {
            config(['sandbox.stripe.enabled' => true, 'sandbox.stripe.secret' => self::SANDBOX_KEY]);
            config($settings);
            $this->sandboxJar = [];
            $this->asSandboxEmployer();

            $this->sandboxGet(route('employer.dashboard'))->assertOk()->assertSee('data-sandbox-simulated', false)->assertDontSee('data-sandbox-stripe', false)
                ->assertSee('Nothing here touches the live demo or Stripe.')
                ->assertSee('Buy credits (simulated, no real money)')->assertDontSee('Buy credits (Stripe test mode, no real money)');
            $this->buy('250')->assertSessionHas('status', fn ($s) => str_contains($s, 'simulated, no real money'));
            $this->assertSame(CreditPurchase::STATUS_SIMULATED, $this->sandboxPurchase()->status, $case);
        }
        $this->assertSame([], $this->checkout->calls);

        config(['sandbox.stripe.enabled' => true, 'sandbox.stripe.secret' => self::SANDBOX_KEY]);
        $this->checkout->down = true;
        $this->sandboxJar = [];
        $this->asSandboxEmployer();
        $this->buy('250')->assertSessionHas('status', fn ($s) => str_contains($s, 'Stripe could not be reached, so this payment was simulated'));
        $this->assertSame(CreditPurchase::STATUS_SIMULATED, $this->sandboxPurchase()->status);
        $this->assertNoLiveStripeOrWrites();
    }

    public function test_at_most_ten_checkouts_per_sandbox_and_the_purchase_cap_still_applies(): void
    {
        $this->asSandboxEmployer();

        $this->buy('5000.01')->assertSessionHasErrors('purchase_amount');
        $this->assertSame(0, $this->checkout->count('create'));

        for ($i = 0; $i < 10; $i++) {
            $this->buy('10')->assertRedirect('https://checkout.stripe.com/c/pay/'.$this->checkout->latestId());
        }
        $this->buy('10')->assertSessionHas('status', fn ($s) => str_contains($s, 'opened its 10 Stripe Checkouts, so this payment was simulated'));
        $this->assertSame(10, $this->checkout->count('create'));
        $this->assertSame(CreditPurchase::STATUS_SIMULATED, $this->sandboxPurchase()->status);
        // With no Checkouts left, the button says what a press now does.
        $this->sandboxGet(route('employer.dashboard'))->assertOk()
            ->assertSee('Buy credits (simulated, no real money)')->assertDontSee('Buy credits (Stripe test mode, no real money)');
        $this->assertNoLiveStripeOrWrites();
    }

    public function test_the_live_site_never_uses_the_sandbox_key_and_a_sandbox_never_uses_the_live_key(): void
    {
        // Live: the sandbox client is off even with the settings on, and Buy credits uses the live account only.
        $this->assertFalse(SandboxStripe::available());
        $this->assertNull(SandboxStripe::client());
        $this->seed(DatabaseSeeder::class);
        $this->signIn(Role::EmployerAdmin);
        $this->post(route('employer.purchases.store'), ['purchase_amount' => '100'])->assertRedirect();
        $live = CreditPurchase::orderByDesc('id')->firstOrFail();
        $this->postJson(route('employer.purchases.checkout', $live))->assertOk();
        $this->assertSame(1, count(array_filter($this->stripe->calls, fn ($c) => $c[0] === 'checkout')));
        $this->assertSame([], $this->checkout->calls, 'The sandbox client was used on the live site');
        $this->get(route('employer.purchases.sandbox-return', $live).'?session_id=x')->assertNotFound();

        // Sandbox: the live keys are blank, and the real client is built with the sandbox key only.
        $this->stripe->calls = [];
        $this->app->forgetInstance(SandboxCheckoutClient::class);
        unset($this->app[SandboxCheckoutClient::class]);
        $this->startSandbox();
        $this->insideSandbox(function () {
            $this->assertNull(config('services.stripe.secret'));
            $this->assertNull(config('services.stripe.key'));
            $client = SandboxStripe::client();
            $this->assertInstanceOf(SandboxStripeClient::class, $client);
            $this->assertTrue($client->usesKey(self::SANDBOX_KEY));
            $this->assertFalse($client->usesKey('sk_test_fake'));
        });
        $this->assertSame([], $this->stripe->calls);

        // Only SandboxStripe reads the sandbox key.
        $readers = collect(File::allFiles(app_path()))->filter(fn ($f) => str_contains($f->getContents(), 'sandbox.stripe.secret'))
            ->map(fn ($f) => $f->getFilename())->values()->all();
        $this->assertSame(['SandboxStripe.php'], $readers);
    }

    public function test_the_deploy_check_refuses_the_live_key_as_the_sandbox_key(): void
    {
        config(['sandbox.stripe.secret' => 'sk_test_fake']);
        $this->assertContains(SandboxStripe::problem(), ProductionConfig::problems());

        config(['sandbox.stripe.enabled' => false]);
        $this->assertNull(SandboxStripe::problem());
        config(['sandbox.stripe.enabled' => true, 'sandbox.stripe.secret' => self::SANDBOX_KEY]);
        $this->assertNull(SandboxStripe::problem());
    }

    public function test_an_employer_refund_of_a_card_purchase_is_simulated_inside_the_sandbox(): void
    {
        $this->asSandboxEmployer();
        $this->buy('250');
        $purchase = $this->sandboxPurchase();
        $this->checkout->pay($id = $this->checkout->latestId());
        $this->returnFor($purchase, $id);
        $this->checkout->calls = [];

        $correction = $this->insideSandbox(function () {
            $employer = Employer::orderBy('id')->firstOrFail();
            $hr = User::where('role', Role::EmployerAdmin)->orderBy('id')->firstOrFail();
            $reviewer = User::where('role', Role::PlatformAdmin)->orderBy('id')->firstOrFail();
            $correction = app(CorrectionRequests::class)->forEmployer($hr, $employer, 'Too many credits bought by mistake', null);
            // Newest payment first: the card purchase just made in this sandbox.
            app(CorrectionDecisions::class)->approveEmployerRefund($correction, $reviewer, 'Approved in the sandbox', 10000);

            return $correction->refresh()->load('actions');
        });

        $this->assertSame(Correction::STATUS_COMPLETED, $correction->status);
        $action = $correction->actions->firstWhere('credit_purchase_id', $purchase->id);
        $this->assertNotNull($action);
        $this->assertSame(CorrectionAction::KIND_SIMULATED, $action->kind);
        $this->assertSame(CorrectionAction::STATUS_SUCCEEDED, $action->status);
        $this->assertSame([], $this->checkout->calls, 'No refund is sent to the sandbox Stripe account');
        $this->assertTrue($this->insideSandbox(fn () => app(SolvencyCheck::class)->now()->isSolvent()));
        $this->assertNoLiveStripeOrWrites();
    }

    public function test_the_checklist_and_the_sandbox_page_follow_the_setting(): void
    {
        $this->get(route('sandbox.show'))->assertOk()->assertSee('Buying credits uses a separate Stripe test account');
        $this->startSandbox();
        $this->get(route('sandbox.show'))->assertSee(SandboxStripe::TEST_CARD_HINT, false);
        $this->sandboxGet(route('sandbox.home'))->assertOk()->assertSee(SandboxStripe::TEST_CARD_HINT, false);
        $this->assertStringContainsString(SandboxStripe::TEST_CARD_HINT, SandboxRoutes::CHECKLIST_STRIPE_BUY);
        $this->assertSame('Use card 4242 4242 4242 4242 with any future expiry date (e.g. 12/34), any 3-digit CVC, any ZIP code and any name. No real money.', SandboxStripe::TEST_CARD_HINT);
        $this->assertSame(SandboxRoutes::CHECKLIST_STRIPE_BUY, $this->insideSandbox(fn () => SandboxRoutes::checklist()[0][1]));

        config(['sandbox.stripe.enabled' => false]);
        $this->get(route('sandbox.show'))->assertSee('Payments are simulated: no card is charged and Stripe is not contacted.');
        $this->sandboxGet(route('sandbox.home'))->assertSee(SandboxRoutes::CHECKLIST[0][1]);
    }
}
