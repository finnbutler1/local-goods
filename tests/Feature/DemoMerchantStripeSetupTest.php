<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\CreditLedger;
use App\Services\DemoResetter;
use App\Services\Eligibility\MerchantCandidates;
use App\Services\Payments\DemoMerchantStripeSetup;
use App\Services\Payments\NoticeSafeStripeGateway;
use App\Services\Payments\StripeGateway;
use App\Services\Payments\StripeGatewayException;
use App\Services\Payments\StripeReconciliation;
use App\Services\RedemptionService;
use App\Support\StripeEnvironment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesFakeStripe;
use Tests\Fakes\FakeClaudeClient;
use Tests\Fakes\FakeStripeGateway;
use Tests\TestCase;

/**
 * "Connect demo merchants to Stripe (test mode)": the platform admin gives
 * every fictional demo merchant with no login a Stripe test account. Never
 * for a merchant with a login, never for a real candidate business, never
 * with live keys, and pressing it twice creates nothing new. One merchant
 * per request (CloudFront waits only 30 seconds), and a request cut off
 * midway is finished by the next press without a duplicate account.
 */
class DemoMerchantStripeSetupTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeStripe;

    private const LOGINLESS = [
        'Seventeenth Street Kitchen', 'Connecticut Noodle Bar', 'Press Room Deli', 'K Street Cuts',
        'Lafayette Cycle Studio', 'Triangle Stems', 'Page & Quill Books',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeStripe();
        $this->signIn(Role::Merchant); // links Farragut Grind to a login, as in the demo
        $this->signIn(Role::PlatformAdmin);
    }

    /** One press of the button. */
    private function connect(int $round = 0)
    {
        return $this->post('/admin/stripe/demo-merchants', ['round' => $round]);
    }

    /** Presses until all are connected, as the page does by itself. @return int presses */
    private function connectAll(): int
    {
        for ($presses = 1; $presses <= 20; $presses++) {
            $this->connect($presses - 1);
            if (session()->has('status')) {
                return $presses;
            }
        }
        $this->fail('Never finished connecting.');
    }

    public function test_each_press_connects_one_merchant_and_the_page_continues_until_all_are_done(): void
    {
        $this->get('/admin/reconciliation')->assertOk()
            ->assertSee('Connect demo merchants to Stripe (test mode)')
            ->assertSee('0 of 7 demo merchants with no login are connected.')
            ->assertDontSee('data-auto-submit', false);

        foreach (self::LOGINLESS as $i => $name) {
            $before = count($this->stripe->calls);
            $response = $this->connect($i);
            $done = $i + 1;

            // Only this merchant's calls: create, bank account, one status read. Nothing waits or sends.
            $this->assertSame($name, $this->stripe->callsOf('demo_account')[$i]['display_name']);
            $this->assertSame(['demo_account', 'bank_account', 'retrieve_account'], array_column(array_slice($this->stripe->calls, $before), 0));
            $this->assertNotNull(Merchant::where('name', $name)->value('stripe_account_id'));

            if ($done < 7) {
                $response->assertRedirect(route('admin.reconciliation', ['connecting' => $done]).'#demo-merchants')
                    ->assertSessionHas('notice', "{$done} of 7 demo merchants connected, continuing…");
                // The page presses again by itself; without JavaScript the button says Continue.
                $this->get(route('admin.reconciliation', ['connecting' => $done]))->assertOk()
                    ->assertSee('data-auto-submit', false)
                    ->assertSee('name="round" value="'.$done.'"', false)
                    ->assertSee("Continue connecting ({$done} of 7 done)")
                    ->assertSee('press again to continue');
                $this->get('/admin/reconciliation')->assertDontSee('data-auto-submit', false);
            } else {
                $response->assertRedirect(route('admin.reconciliation').'#demo-merchants')
                    ->assertSessionHas('status', fn ($m) => str_starts_with($m, 'All 7 demo merchants are connected to Stripe (test mode).'));
            }
        }

        $created = $this->stripe->callsOf('demo_account');
        $this->assertSame(self::LOGINLESS, array_column($created, 'display_name'));
        foreach ($created as $params) {
            $this->assertSame('1', $params['metadata']['lg_demo_setup']);
            $this->assertSame(StripeEnvironment::tag(), array_intersect_key($params['metadata'], StripeEnvironment::tag()));
            $this->assertArrayHasKey('terms_of_service', $params['identity']['attestations']);
        }
        $this->assertSame([], $this->stripe->callsOf('account'), 'No Express account is made by the platform');
        $this->assertSame([], $this->stripe->callsOf('transfer'), 'A step never sends money');

        foreach (self::LOGINLESS as $name) {
            $merchant = Merchant::where('name', $name)->firstOrFail();
            $this->assertTrue($merchant->isStripeOnboarded(), $name);
            $this->assertTrue($merchant->stripe_set_up_by_platform, $name);
            $this->assertSame(Merchant::PLATFORM_SETUP_LABEL, $merchant->stripeOnboardingLabel());
        }

        $this->get('/admin/reconciliation?connecting=7')->assertOk()
            ->assertSee('All 7 demo merchants with no login are connected.')
            ->assertDontSee('data-auto-submit', false)
            ->assertDontSee('Continue connecting');

        // Pressing again (an old tab, a double click) creates nothing new and calls Stripe for nothing.
        $calls = count($this->stripe->calls);
        $this->connect()->assertSessionHas('status', fn ($m) => str_starts_with($m, 'All 7 demo merchants'));
        $this->assertCount($calls, $this->stripe->calls);
        $this->assertSame(7, Merchant::whereNotNull('stripe_account_id')->count());
    }

    public function test_a_request_cut_off_after_stripe_made_the_account_is_finished_by_the_next_press(): void
    {
        // Stripe creates the account, but the reply never arrives (a timeout).
        $fake = new class extends FakeStripeGateway
        {
            public bool $cutOff = true;

            public function createDemoAccount(array $params, string $idempotencyKey): array
            {
                $account = parent::createDemoAccount($params, $idempotencyKey);
                if ($this->cutOff) {
                    $this->cutOff = false;
                    throw new StripeGatewayException('Could not reach Stripe. Nothing was lost; it will be tried again.', definite: false);
                }

                return $account;
            }
        };
        $this->stripe = $fake;
        $this->app->instance(StripeGateway::class, new NoticeSafeStripeGateway($fake));

        $this->connect()->assertSessionHas('error', fn ($m) => str_starts_with($m, 'Seventeenth Street Kitchen: Could not reach Stripe.') && str_contains($m, 'nothing is duplicated'));
        $this->assertCount(1, $fake->accounts, 'Stripe made the account');
        $this->assertNull(Merchant::where('name', 'Seventeenth Street Kitchen')->value('stripe_account_id'));
        $this->get('/admin/reconciliation')->assertSee('Connect demo merchants to Stripe (test mode)')->assertSee('0 of 7 demo merchants with no login are connected.');

        // The next press asks with the same key and gets the same account back.
        $this->connect()->assertSessionHas('notice', '1 of 7 demo merchants connected, continuing…');
        $this->assertCount(1, $fake->accounts, 'No second account for the same merchant');
        $this->assertSame(array_key_first($fake->accounts), Merchant::where('name', 'Seventeenth Street Kitchen')->value('stripe_account_id'));

        $this->connectAll();
        $this->assertCount(7, $fake->accounts);
        $this->assertSame(7, Merchant::whereNotNull('stripe_account_id')->distinct()->count('stripe_account_id'));
    }

    public function test_a_request_cut_off_before_the_bank_account_is_finished_by_the_next_press(): void
    {
        $fake = new class extends FakeStripeGateway
        {
            public bool $cutOff = true;

            public function addTestBankAccount(string $accountId, string $idempotencyKey): array
            {
                if ($this->cutOff) {
                    $this->cutOff = false;
                    throw new StripeGatewayException('Could not reach Stripe. Nothing was lost; it will be tried again.', definite: false);
                }

                return parent::addTestBankAccount($accountId, $idempotencyKey);
            }
        };
        $this->stripe = $fake;
        $this->app->instance(StripeGateway::class, new NoticeSafeStripeGateway($fake));

        $this->connect()->assertSessionHas('error');
        $kitchen = Merchant::where('name', 'Seventeenth Street Kitchen')->firstOrFail();
        $this->assertNotNull($kitchen->stripe_account_id, 'The id is saved before anything else can go wrong');
        $this->assertSame('0 of 7', $this->progress());

        $this->connect()->assertSessionHas('notice', '1 of 7 demo merchants connected, continuing…');
        $this->assertCount(1, $fake->callsOf('demo_account'), 'Only the bank account was added; no new account');
        $this->assertSame($kitchen->stripe_account_id, $kitchen->fresh()->stripe_account_id);
        $this->assertTrue($kitchen->fresh()->stripe_payouts_enabled);
    }

    public function test_the_automatic_presses_stop_if_stripe_keeps_a_merchant_waiting(): void
    {
        // Stripe accepts the bank account but its status hasn't caught up yet.
        $fake = new class extends FakeStripeGateway
        {
            public function addTestBankAccount(string $accountId, string $idempotencyKey): array
            {
                $this->calls[] = ['bank_account', ['account' => $accountId]];

                return ['id' => 'ba_test_slow', 'object' => 'bank_account'];
            }
        };
        $this->stripe = $fake;
        $this->app->instance(StripeGateway::class, new NoticeSafeStripeGateway($fake));

        $this->connect(13)->assertRedirect(route('admin.reconciliation').'#demo-merchants')
            ->assertSessionHas('notice', '0 of 7 demo merchants connected. Press Continue connecting to go on.');
        $this->assertCount(1, $fake->callsOf('demo_account'));
    }

    private function progress(): string
    {
        $p = app(DemoMerchantStripeSetup::class)->progress();

        return $p['done'].' of '.$p['total'];
    }

    public function test_a_merchant_with_a_login_keeps_its_own_onboarding(): void
    {
        $this->connectAll();

        $farragut = Merchant::where('name', 'Farragut Grind')->firstOrFail();
        $this->assertNull($farragut->stripe_account_id);
        $this->assertFalse($farragut->stripe_set_up_by_platform);

        $this->signIn(Role::Merchant);
        $this->get('/merchant')->assertOk()->assertSee('Start Stripe onboarding')->assertDontSee(Merchant::PLATFORM_SETUP_LABEL);
    }

    public function test_it_refuses_with_live_keys_and_touches_nothing(): void
    {
        config(['services.stripe.key' => 'pk_live_fake_not_a_real_key', 'services.stripe.secret' => 'sk_live_fake_not_a_real_key']);

        $this->connect()->assertSessionHas('error', fn ($m) => str_contains($m, 'not test-mode keys') && str_contains($m, 'Nothing was created.'));
        $this->assertSame([], $this->stripe->calls);
        $this->assertSame(0, Merchant::whereNotNull('stripe_account_id')->orWhere('stripe_set_up_by_platform', true)->count());
        $this->get('/admin/reconciliation')->assertOk()->assertDontSee('Connect demo merchants to Stripe (test mode)');
    }

    public function test_it_never_touches_a_real_candidate_business(): void
    {
        // A real candidate as a merchant row (never happens in the demo), and
        // one disguised under a demo merchant's name: neither is touched.
        $real = collect(MerchantCandidates::load())->first();
        $bid = Merchant::firstOrFail()->bid_id;
        $candidate = Merchant::create(['bid_id' => $bid, 'name' => $real['name'], 'category' => 'restaurant', 'address' => $real['address']]);
        config(['eligibility.candidates_path' => $this->candidatesWith('Press Room Deli')]);

        $this->connectAll();

        $this->assertNull($candidate->fresh()->stripe_account_id);
        $this->assertNull(Merchant::where('name', 'Press Room Deli')->value('stripe_account_id'));
        $names = collect(MerchantCandidates::load())->pluck('name');
        foreach ($this->stripe->callsOf('demo_account') as $params) {
            $this->assertNotContains($params['display_name'], $names);
            $this->assertNotContains($params['metadata']['lg_merchant'], $names);
        }
        $this->assertCount(6, $this->stripe->callsOf('demo_account'));
    }

    public function test_a_cleared_redemption_at_a_connected_merchant_is_transferred_to_its_own_account(): void
    {
        $this->connectAll();
        $deli = Merchant::where('name', 'Press Room Deli')->firstOrFail();

        $ledger = app(CreditLedger::class);
        $purchase = $ledger->requestPurchase(Employer::firstOrFail(), 50000, 'MPG-STRIPE-DEMO');
        $ledger->markPurchasePaidByStripe($purchase, 'pi_test_demo', 'ch_test_demo', 50000, 'usd');

        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(10, 'clear'));
        app(RedemptionService::class)->redeem(Employee::orderBy('id')->firstOrFail(), $deli, 1200);

        $transfers = $this->stripe->callsOf('transfer');
        $this->assertCount(1, $transfers);
        $this->assertSame($deli->stripe_account_id, $transfers[0]['destination']);
        $this->assertNotSame(Merchant::where('name', 'Farragut Grind')->value('stripe_account_id'), $transfers[0]['destination']);
        $this->assertSame(1200, $transfers[0]['amount']);

        $this->stripe->paymentIntents = [[
            'id' => 'pi_test_demo', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 50000, 'amount_received' => 50000,
            'metadata' => ['lg_purchase_token' => 'tok_demo'] + StripeEnvironment::tag(),
        ]];
        $this->assertSame([], app(StripeReconciliation::class)->report()['mismatches']);
    }

    public function test_each_merchant_dashboard_shows_its_own_stripe_status(): void
    {
        $this->connectAll();

        foreach (['Press Room Deli', 'Triangle Stems'] as $name) {
            $merchant = Merchant::where('name', $name)->firstOrFail();
            $user = User::factory()->role(Role::Merchant)->create();
            $merchant->update(['user_id' => $user->id]);
            $this->actingAs($user);

            $this->get('/merchant')->assertOk()
                ->assertSee($merchant->stripe_account_id)
                ->assertSee(Merchant::PLATFORM_SETUP_LABEL)
                ->assertSee("Real merchants always onboard themselves through Stripe's Express pages.", false);
        }

        $this->signIn(Role::PlatformAdmin);
        $page = $this->get('/admin/reconciliation')->assertOk()->assertSee('Demo merchants on Stripe');
        foreach (Merchant::whereNotNull('stripe_account_id')->get() as $merchant) {
            $page->assertSee($merchant->stripe_account_id);
        }
        $this->get('/bid-admin/participation')->assertOk()->assertSee(Merchant::PLATFORM_SETUP_LABEL);
    }

    public function test_judges_and_other_roles_cannot_run_it(): void
    {
        foreach ([Role::Judge, Role::BidAdmin, Role::EmployerAdmin, Role::Merchant, Role::Employee] as $role) {
            $this->signIn($role);
            $this->assertContains($this->connect()->status(), [302, 403], $role->value);
        }

        $this->assertSame([], $this->stripe->calls);
        $this->assertSame(0, Merchant::whereNotNull('stripe_account_id')->count());
    }

    public function test_reset_demo_keeps_the_demo_accounts_and_their_label(): void
    {
        $this->connectAll();
        $before = Merchant::whereNotNull('stripe_account_id')->pluck('stripe_account_id', 'name');

        app(DemoResetter::class)->reset();

        $this->assertEquals($before, Merchant::whereNotNull('stripe_account_id')->pluck('stripe_account_id', 'name'));
        $this->assertSame(7, Merchant::where('stripe_set_up_by_platform', true)->count());
    }

    public function test_a_stripe_refusal_names_the_merchant_and_the_next_press_tries_it_again(): void
    {
        $fake = new class extends FakeStripeGateway
        {
            public int $refusals = 1;

            public function createDemoAccount(array $params, string $idempotencyKey): array
            {
                if ($params['display_name'] === 'Seventeenth Street Kitchen' && $this->refusals-- > 0) {
                    throw new StripeGatewayException('Stripe refused the request (invalid_request).');
                }

                return parent::createDemoAccount($params, $idempotencyKey);
            }
        };
        $this->app->instance(StripeGateway::class, new NoticeSafeStripeGateway($fake));

        $this->connect()->assertSessionHas('error', 'Seventeenth Street Kitchen: Stripe refused the request (invalid_request). Press Continue to try that merchant again; nothing is duplicated.');
        $this->assertSame('Not connected to Stripe', Merchant::where('name', 'Seventeenth Street Kitchen')->firstOrFail()->stripeOnboardingLabel());

        $this->connect()->assertSessionHas('notice', '1 of 7 demo merchants connected, continuing…');
        $this->assertNotNull(Merchant::where('name', 'Seventeenth Street Kitchen')->value('stripe_account_id'));
    }

    /** A copy of the candidate list with one extra (pretend) real business under $name. */
    private function candidatesWith(string $name): string
    {
        $path = tempnam(sys_get_temp_dir(), 'candidates');
        $csv = file_get_contents(config('eligibility.candidates_path'));
        file_put_contents($path, rtrim($csv)."\nMC-999,\"{$name}\",\"1 EXAMPLE ST NW, WASHINGTON, DC, 20006\",restaurant,https://example.com\n");

        return $path;
    }
}
