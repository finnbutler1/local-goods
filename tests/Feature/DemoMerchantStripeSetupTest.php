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
 * with live keys, and pressing it twice creates nothing new.
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

    private function connect()
    {
        return $this->post('/admin/stripe/demo-merchants');
    }

    public function test_it_connects_each_demo_merchant_with_no_login_once(): void
    {
        $this->connect()->assertRedirect(route('admin.reconciliation').'#demo-merchants')
            ->assertSessionHas('status', 'Connected 7 demo merchants to Stripe (test mode); 0 already connected. Farragut Grind has a login and onboards itself.');

        $created = $this->stripe->callsOf('demo_account');
        $this->assertSame(self::LOGINLESS, array_column($created, 'display_name'));
        foreach ($created as $params) {
            $this->assertSame('1', $params['metadata']['lg_demo_setup']);
            $this->assertSame(StripeEnvironment::tag(), array_intersect_key($params['metadata'], StripeEnvironment::tag()));
            $this->assertArrayHasKey('terms_of_service', $params['identity']['attestations']);
        }
        $this->assertCount(7, $this->stripe->callsOf('bank_account'));
        $this->assertSame([], $this->stripe->callsOf('account'), 'No Express account is made by the platform');

        foreach (self::LOGINLESS as $name) {
            $merchant = Merchant::where('name', $name)->firstOrFail();
            $this->assertTrue($merchant->isStripeOnboarded(), $name);
            $this->assertTrue($merchant->stripe_payouts_enabled, $name);
            $this->assertTrue($merchant->stripe_set_up_by_platform, $name);
            $this->assertSame(Merchant::PLATFORM_SETUP_LABEL, $merchant->stripeOnboardingLabel());
        }

        // Pressing it again creates nothing new.
        $accounts = count($this->stripe->accounts);
        $this->connect()->assertSessionHas('status', 'Connected 0 demo merchants to Stripe (test mode); 7 already connected. Farragut Grind has a login and onboards itself.');
        $this->assertCount(7, $this->stripe->callsOf('demo_account'));
        $this->assertCount(7, $this->stripe->callsOf('bank_account'));
        $this->assertCount($accounts, $this->stripe->accounts);
        $this->assertSame(7, Merchant::whereNotNull('stripe_account_id')->count());
    }

    public function test_a_merchant_with_a_login_keeps_its_own_onboarding(): void
    {
        $this->connect();

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

        $this->connect();

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
        $this->connect();
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
        $this->connect();

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
        $this->connect();
        $before = Merchant::whereNotNull('stripe_account_id')->pluck('stripe_account_id', 'name');

        app(DemoResetter::class)->reset();

        $this->assertEquals($before, Merchant::whereNotNull('stripe_account_id')->pluck('stripe_account_id', 'name'));
        $this->assertSame(7, Merchant::where('stripe_set_up_by_platform', true)->count());
    }

    public function test_a_stripe_refusal_for_one_merchant_does_not_stop_the_others(): void
    {
        $this->stripe->accounts = [];
        $fake = new class extends FakeStripeGateway
        {
            public function createDemoAccount(array $params, string $idempotencyKey): array
            {
                if ($params['display_name'] === 'K Street Cuts') {
                    throw new StripeGatewayException('Stripe refused the request (invalid_request).');
                }

                return parent::createDemoAccount($params, $idempotencyKey);
            }
        };
        $this->app->instance(StripeGateway::class, new NoticeSafeStripeGateway($fake));

        $this->connect()->assertSessionHas('error', fn ($m) => str_contains($m, 'Connected 6 demo merchants') && str_contains($m, 'K Street Cuts: Stripe refused the request (invalid_request).'));
        $this->assertNull(Merchant::where('name', 'K Street Cuts')->value('stripe_account_id'));
        $this->assertSame('Not connected to Stripe', Merchant::where('name', 'K Street Cuts')->firstOrFail()->stripeOnboardingLabel());
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
