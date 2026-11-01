<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Merchant;
use App\Models\OperatingAccount;
use App\Services\DemoResetter;
use App\Services\Payments\OperatingAccountSetup;
use App\Services\Payments\StripeGatewayException;
use App\Services\Payments\StripeWebhookHandler;
use App\Support\StripeEnvironment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * Local Goods Operating (ADR 0034): one account per environment, made by
 * the platform admin, test mode only, tagged with this environment, never a
 * bank account, kept by Reset demo, and invisible to the merchant webhook.
 */
class OperatingAccountSetupTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeStripe;

    public function test_the_platform_admin_creates_one_tagged_account_with_no_bank_account(): void
    {
        $this->fakeStripe();
        $this->signIn(Role::PlatformAdmin);

        $this->post(route('admin.stripe.operating-account'))->assertRedirect(route('admin.reconciliation').'#operating-account')
            ->assertSessionHas('status', 'Local Goods Operating is set up in Stripe (test mode) and can receive sweeps.');

        $record = OperatingAccount::current();
        $this->assertNotNull($record->stripe_account_id);
        $this->assertTrue($record->transfers_active);

        [$params] = $this->stripe->callsOf('operating_account');
        $this->assertSame(OperatingAccountSetup::DISPLAY_NAME, $params['display_name']);
        $this->assertSame('1', $params['metadata']['lg_operating_account']);
        $this->assertArrayHasKey('lg_instance', $params['metadata']);
        $this->assertSame([], $this->stripe->callsOf('bank_account'), 'No bank account, so Stripe never pays it out.');
        $this->assertSame([], $this->stripe->callsOf('demo_account'), 'Never made through the demo merchant path.');
    }

    public function test_pressing_again_creates_nothing_new(): void
    {
        $this->fakeStripe();
        $setup = app(OperatingAccountSetup::class);

        $first = $setup->create('127.0.0.1');
        $again = $setup->create('127.0.0.1');

        $this->assertSame($first->stripe_account_id, $again->stripe_account_id);
        $this->assertSame(1, OperatingAccount::count());
        $this->assertCount(1, $this->stripe->callsOf('operating_account'));
    }

    public function test_a_request_cut_off_before_the_id_was_saved_reuses_the_same_stripe_key(): void
    {
        $this->fakeStripe();
        OperatingAccount::create(['setup_key' => 'fake-setup-key-example']);

        app(OperatingAccountSetup::class)->create('127.0.0.1');

        $this->assertSame(1, OperatingAccount::count());
        $this->assertSame('fake-setup-key-example', OperatingAccount::current()->setup_key);
    }

    public function test_refused_without_stripe_test_mode_and_nothing_is_saved(): void
    {
        $this->expectException(StripeGatewayException::class);

        try {
            app(OperatingAccountSetup::class)->create('127.0.0.1');
        } finally {
            $this->assertSame(0, OperatingAccount::count());
        }
    }

    public function test_only_the_platform_admin_may_create_it(): void
    {
        $this->fakeStripe();
        foreach ([Role::BidAdmin, Role::EmployerAdmin, Role::Merchant, Role::Employee] as $role) {
            $this->signIn($role);
            $this->post(route('admin.stripe.operating-account'))->assertForbidden();
        }
        $this->assertSame(0, OperatingAccount::count());
    }

    public function test_reconciliation_shows_the_account_and_its_label(): void
    {
        $this->fakeStripe();
        $this->signIn(Role::PlatformAdmin);

        $this->get(route('admin.reconciliation'))->assertOk()->assertSee('data-operating-account="none"', false)
            ->assertSee('Create the Local Goods Operating account (test mode)');

        app(OperatingAccountSetup::class)->create('127.0.0.1');

        $this->get(route('admin.reconciliation'))->assertOk()->assertSee('data-operating-account="ready"', false)
            ->assertSee(OperatingAccount::LABEL)->assertSee('payouts are off on purpose')
            ->assertDontSee('Create the Local Goods Operating account (test mode)');
    }

    public function test_reset_demo_keeps_it(): void
    {
        $this->fakeStripe();
        $this->seed(DatabaseSeeder::class);
        $id = app(OperatingAccountSetup::class)->create('127.0.0.1')->stripe_account_id;

        app(DemoResetter::class)->reset();

        $this->assertSame($id, OperatingAccount::current()?->stripe_account_id);
    }

    public function test_the_merchant_webhook_ignores_its_account_updates(): void
    {
        $this->fakeStripe();
        $account = app(OperatingAccountSetup::class)->create('127.0.0.1');

        $outcome = app(StripeWebhookHandler::class)->handle($this->stripeEvent('account.updated',
            $this->stripe->accounts[$account->stripe_account_id] + ['metadata' => StripeEnvironment::tag()]));

        $this->assertStringStartsWith('ignored', $outcome);
        $this->assertSame(0, Merchant::where('stripe_account_id', $account->stripe_account_id)->count());
    }
}
