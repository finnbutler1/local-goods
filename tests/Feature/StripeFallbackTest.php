<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CreditPurchase;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Services\Ai\ClaudeClient;
use App\Services\Payments\StripeGateway;
use App\Support\StripeMode;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\FakeClaudeClient;
use Tests\Fakes\FakeStripeGateway;
use Tests\TestCase;

/**
 * With Stripe keys missing (CI, tests, a misconfigured server) or live
 * keys set, everything keeps working in simulated mode, says so, and never
 * calls Stripe or pretends a simulated payment is a Stripe one (ADR 0015).
 */
class StripeFallbackTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        // Bound only to prove nothing calls it.
        $this->stripe = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $this->stripe);
    }

    public static function configs(): array
    {
        return [
            'no keys' => [[]],
            'only the secret key' => [['services.stripe.secret' => 'sk_test_x']],
            'no webhook secret' => [['services.stripe.key' => 'pk_test_x', 'services.stripe.secret' => 'sk_test_x']],
            'live keys' => [['services.stripe.key' => 'pk_live_x', 'services.stripe.secret' => 'sk_live_x', 'services.stripe.webhook_secret' => 'whsec_x']],
        ];
    }

    #[DataProvider('configs')]
    public function test_it_falls_back_to_simulated_and_never_calls_stripe(array $config): void
    {
        config($config);
        $this->assertFalse(StripeMode::active());
        $this->assertNotNull(StripeMode::problem());

        // Employer: buying is one step, simulated and labeled as such.
        $this->signIn(Role::EmployerAdmin);
        $this->get('/employer')->assertOk()->assertSee('Buy credits (simulated, no real money)')->assertSee('Simulated')
            ->assertDontSee('Continue paying')->assertDontSee('4000 0000 0000 0077')->assertDontSee('js.stripe.com');
        $this->post('/employer/purchases', ['purchase_amount' => '100'])->assertRedirect();
        $purchase = CreditPurchase::latest('id')->firstOrFail();
        $this->assertSame(CreditPurchase::STATUS_SIMULATED, $purchase->fresh()->status);
        // Even with ?pay= in the address, no Stripe form appears.
        $this->get('/employer?pay='.$purchase->reference)->assertOk()->assertDontSee('data-embedded-checkout', false);
        $this->assertNull($purchase->fresh()->stripe_payment_intent_id);

        // A cleared redemption: owed, simulated, no Stripe row.
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(10, 'clear'));
        $this->signIn(Role::Employee);
        $this->get('/employee')->assertOk()->assertSee('Merchant payouts are simulated on this server');
        $this->post('/employee/redeem', ['merchant_id' => Merchant::firstOrFail()->id, 'amount' => '5'])->assertRedirect();
        $this->assertSame(0, MerchantPayout::stripe()->count());

        // Merchant, BID and admin pages say simulated.
        $this->signIn(Role::Merchant);
        $this->get('/merchant')->assertOk()->assertSee("Stripe isn't connected on this server", false)->assertDontSee('Start Stripe onboarding');
        $this->signIn(Role::BidAdmin);
        $this->get('/bid-admin')->assertOk()->assertSee("Stripe isn't connected on this server");
        $this->get('/bid-admin/participation')->assertOk()->assertSee('Simulated');
        $this->signIn(Role::PlatformAdmin);
        $this->get('/admin/reconciliation')->assertOk()->assertSee('Stripe test mode is off');

        $this->assertSame([], $this->stripe->calls, 'Stripe was called in simulated mode');
        $this->assertSame(0, CreditPurchase::where('status', CreditPurchase::STATUS_PAID)->count());
    }

    public function test_test_keys_switch_stripe_test_mode_on(): void
    {
        config(['services.stripe.key' => 'pk_test_x', 'services.stripe.secret' => 'sk_test_x', 'services.stripe.webhook_secret' => 'whsec_x']);

        $this->assertTrue(StripeMode::active());
        $this->assertSame('Stripe test mode', StripeMode::label());
    }

    public function test_seeded_purchases_and_payouts_are_labeled_simulated_never_stripe(): void
    {
        config(['services.stripe.key' => 'pk_test_x', 'services.stripe.secret' => 'sk_test_x', 'services.stripe.webhook_secret' => 'whsec_x']);
        $this->assertSame(0, CreditPurchase::where('status', CreditPurchase::STATUS_PAID)->count());
        $this->assertSame(0, MerchantPayout::stripe()->count());

        $this->signIn(Role::EmployerAdmin);
        $html = $this->get('/employer')->assertOk()->getContent();
        $this->assertGreaterThan(0, substr_count($html, 'Paid (simulated)'));
        $this->assertStringNotContainsString('pi_', $html);
    }
}
