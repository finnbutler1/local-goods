<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Merchant;
use App\Services\DemoResetter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * Stripe Express onboarding for the fictional demo merchants (ADR 0015).
 * Priya, the demo merchant login, onboards Farragut Grind.
 */
class StripeOnboardingTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeStripe;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeStripe();
        $this->signIn(Role::Merchant);
        $this->merchant = Merchant::where('name', config('demo.accounts.merchant.business'))->firstOrFail();
    }

    public function test_the_merchant_starts_onboarding_and_the_account_id_is_stored(): void
    {
        $this->get('/merchant')->assertOk()->assertSee('Not connected to Stripe')->assertSee('Start Stripe onboarding (test mode)');

        $this->post('/merchant/stripe/onboard')->assertRedirect('https://connect.stripe.test/setup/acct_test_0001');

        $this->assertSame('acct_test_0001', $this->merchant->fresh()->stripe_account_id);
        $this->assertSame('in_progress', $this->merchant->fresh()->stripeOnboardingStatus());
        [$account] = $this->stripe->callsOf('account');
        $this->assertSame('Farragut Grind', $account['display_name']);
        $this->assertSame(['stripe_balance' => ['stripe_transfers' => ['requested' => true]]], $account['configuration']['recipient']['capabilities']);
        [$link] = $this->stripe->callsOf('account_link');
        $this->assertSame([route('merchant.stripe.refresh'), route('merchant.stripe.return')], [$link['refreshUrl'], $link['returnUrl']]);

        // A second press reuses the account and only makes a new link.
        $this->post('/merchant/stripe/onboard');
        $this->assertCount(1, $this->stripe->callsOf('account'));
        $this->assertCount(2, $this->stripe->callsOf('account_link'));
    }

    public function test_coming_back_checks_stripe_and_shows_the_status_on_the_dashboard_and_roster(): void
    {
        $this->post('/merchant/stripe/onboard');
        $this->get('/merchant/stripe/return')->assertRedirect(route('merchant.dashboard').'#stripe')
            ->assertSessionHas('notice', fn ($m) => str_contains($m, 'Money owed through Stripe waits until it is complete.'));
        $this->assertFalse($this->merchant->fresh()->isStripeOnboarded());

        $this->stripe->completeOnboarding('acct_test_0001');
        $this->followingRedirects()->get('/merchant/stripe/return')
            ->assertSee('data-toast="status"', false)->assertSee('Stripe onboarding complete.');
        $this->followRedirects = false;

        $this->assertTrue($this->merchant->fresh()->isStripeOnboarded());
        $this->get('/merchant')->assertSee('Stripe onboarding complete')->assertSee('acct_test_0001')->assertDontSee('Start Stripe onboarding');

        $this->signIn(Role::BidAdmin);
        $this->get('/bid-admin/participation')->assertOk()->assertSee('Stripe onboarding complete')->assertSee('acct_test_0001');
    }

    public function test_the_refresh_page_creates_nothing_until_the_button_is_pressed(): void
    {
        $this->get('/merchant/stripe/refresh')->assertOk()->assertSee('Continue Stripe onboarding (test mode)');

        $this->assertSame([], $this->stripe->calls);
    }

    public function test_an_account_updated_webhook_syncs_the_status(): void
    {
        $this->post('/merchant/stripe/onboard');
        auth()->logout();

        $account = $this->stripe->completeOnboarding('acct_test_0001');
        $this->postWebhook($this->stripeEvent('account.updated', $account, account: 'acct_test_0001'))
            ->assertOk()->assertJson(['outcome' => 'onboarded: Farragut Grind']);

        $this->assertTrue($this->merchant->fresh()->isStripeOnboarded());
    }

    public function test_judges_see_the_status_but_never_a_button_and_cannot_onboard(): void
    {
        $this->post('/merchant/stripe/onboard');
        $this->signIn(Role::Judge);
        $this->get('/judge/view-as/merchant');

        $this->get('/merchant')->assertOk()
            ->assertSee('Stripe onboarding started, not finished')
            ->assertSee('acct_test_0001')
            ->assertSee("Read-only: judges can't onboard", false)
            ->assertDontSee('Start Stripe onboarding')
            ->assertDontSee(route('merchant.stripe.onboard'));
        $this->get('/merchant/stripe/refresh')->assertOk()->assertDontSee('Continue Stripe onboarding (test mode)');

        $calls = count($this->stripe->calls);
        $this->post('/merchant/stripe/onboard')->assertForbidden();
        $this->post('/merchant/stripe/sync')->assertForbidden();
        $this->stripe->completeOnboarding('acct_test_0001');
        $this->get('/merchant/stripe/return')->assertRedirect(route('merchant.dashboard'));

        $this->assertCount($calls, $this->stripe->calls, 'A judge reached Stripe');
        $this->assertFalse($this->merchant->fresh()->isStripeOnboarded());
    }

    public function test_other_roles_cannot_onboard(): void
    {
        $this->signIn(Role::EmployerAdmin);
        $this->post('/merchant/stripe/onboard')->assertForbidden();
        $this->assertSame([], $this->stripe->calls);
    }

    public function test_reset_demo_keeps_the_merchants_stripe_account(): void
    {
        $this->post('/merchant/stripe/onboard');
        $this->stripe->completeOnboarding('acct_test_0001');
        $this->get('/merchant/stripe/return');

        app(DemoResetter::class)->reset();

        $merchant = Merchant::where('name', 'Farragut Grind')->firstOrFail();
        $this->assertSame('acct_test_0001', $merchant->stripe_account_id);
        $this->assertTrue($merchant->isStripeOnboarded());
        $this->assertSame(1, Merchant::whereNotNull('stripe_account_id')->count());
    }

    public function test_without_stripe_keys_there_is_nothing_to_onboard(): void
    {
        config(['services.stripe.key' => null]);

        $this->get('/merchant')->assertOk()->assertSee("Stripe isn't connected on this server", false)->assertDontSee('Start Stripe onboarding');
        $this->post('/merchant/stripe/onboard')->assertForbidden();
        $this->assertSame([], $this->stripe->calls);
    }

    /**
     * The localhost 500 of 2026-10-16: stripe-php raises Stripe's
     * "consider Accounts v2" notice as a PHP warning, which Laravel turned
     * into an ErrorException. A notice must never crash onboarding.
     */
    public function test_a_stripe_notice_during_account_creation_does_not_stop_onboarding(): void
    {
        $this->stripe->accountNotice = 'We recommend building your integration using Accounts v2. '
            ."\nTo suppress Stripe notices in test and sandbox environments, set the STRIPE_SUPPRESS_NOTICES environment variable to true.";

        $this->post('/merchant/stripe/onboard')->assertRedirect('https://connect.stripe.test/setup/acct_test_0001');

        $this->assertSame('acct_test_0001', $this->merchant->fresh()->stripe_account_id);
    }

    public function test_any_onboarding_failure_shows_a_friendly_message_not_a_500_and_logs_no_keys(): void
    {
        config(['services.stripe.secret' => 'sk_test_SECRETVALUE']);
        $this->app->instance(\App\Services\Payments\StripeGateway::class, new class extends \Tests\Fakes\FakeStripeGateway
        {
            public function createExpressAccount(array $params, string $idempotencyKey): array
            {
                throw new \RuntimeException('boom with sk_test_SECRETVALUE inside');
            }
        });
        $logged = [];
        \Illuminate\Support\Facades\Log::listen(function ($message) use (&$logged) {
            $logged[] = json_encode([$message->message, $message->context]);
        });

        $this->post('/merchant/stripe/onboard')->assertRedirect(route('merchant.dashboard').'#stripe');

        $this->get('/merchant')->assertOk()->assertSee('Something went wrong talking to Stripe. Nothing was lost: press the button again in a moment.');
        $this->assertNotEmpty($logged);
        $this->assertStringContainsString('RuntimeException', implode(' ', $logged));
        $this->assertStringNotContainsString('SECRETVALUE', implode(' ', $logged));
        $this->assertNull($this->merchant->fresh()->stripe_account_id);
    }

    public function test_a_stripe_refusal_shows_its_reason_on_the_merchant_dashboard(): void
    {
        $this->app->instance(\App\Services\Payments\StripeGateway::class, new class extends \Tests\Fakes\FakeStripeGateway
        {
            public function createExpressAccount(array $params, string $idempotencyKey): array
            {
                throw new \App\Services\Payments\StripeGatewayException('Stripe refused the request (InvalidRequestException, field capabilities).');
            }
        });

        $this->post('/merchant/stripe/onboard')->assertRedirect(route('merchant.dashboard').'#stripe');
        $this->get('/merchant')->assertOk()->assertSee('Stripe refused the request (InvalidRequestException, field capabilities).');
    }

    /**
     * The 2026-10-16 refusal: Stripe no longer creates v1 Express accounts
     * for this platform and requires a contact email for a v2 account that
     * receives transfers. We create v2 accounts with the Express dashboard,
     * under a key that can't collide with the refused v1 attempts.
     */
    public function test_accounts_are_created_with_accounts_v2_and_a_fresh_key(): void
    {
        $this->post('/merchant/stripe/onboard');

        [$account] = $this->stripe->callsOf('account');
        $this->assertSame(['country' => 'US'], $account['identity']);
        $this->assertSame($this->merchant->fresh()->user->email, $account['contact_email']);
        $this->assertSame(['fees_collector' => 'application', 'losses_collector' => 'application'], $account['defaults']['responsibilities']);
        $this->assertArrayNotHasKey('type', $account, 'v1 Express field');
        $this->assertArrayNotHasKey('capabilities', $account, 'v1 field');

        $key = $this->stripe->idempotencyKeys[0];
        $this->assertSame('lg-account-v2-'.$this->merchant->fresh()->stripe_onboarding_key, $key);
    }

    public function test_status_is_read_from_both_the_v2_account_and_a_v1_webhook_snapshot(): void
    {
        $this->post('/merchant/stripe/onboard');
        $this->assertSame('in_progress', $this->merchant->fresh()->stripeOnboardingStatus());
        $this->assertFalse($this->merchant->fresh()->stripe_details_submitted, 'Stripe is waiting on the merchant');

        auth()->logout();
        $this->postWebhook($this->stripeEvent('account.updated', [
            'id' => 'acct_test_0001', 'object' => 'account', 'details_submitted' => true, 'payouts_enabled' => true, 'capabilities' => ['transfers' => 'active'],
        ]))->assertOk();

        $this->assertTrue($this->merchant->fresh()->isStripeOnboarded());
    }

    public function test_stripes_reason_is_shown_on_the_merchant_dashboard(): void
    {
        $this->signIn(Role::Merchant);
        $this->app->instance(\App\Services\Payments\StripeGateway::class, new class extends \Tests\Fakes\FakeStripeGateway
        {
            public function createExpressAccount(array $params, string $idempotencyKey): array
            {
                throw new \App\Services\Payments\StripeGatewayException('Stripe refused the request (InvalidRequestException). Stripe says: There are required fields missing (set once at account creation): - contact_email: an email address for the account');
            }
        });

        $this->post('/merchant/stripe/onboard')->assertRedirect(route('merchant.dashboard').'#stripe');
        $this->get('/merchant')->assertOk()
            ->assertSee('Stripe says: There are required fields missing')
            ->assertSee('contact_email: an email address for the account')
            ->assertSee('Nothing was lost: press the button again in a moment.');
    }
}
