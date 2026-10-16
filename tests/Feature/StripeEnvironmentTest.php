<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Models\StripeEvent;
use App\Services\Ai\ClaudeClient;
use App\Services\Payments\StripeOnboarding;
use App\Services\RedemptionService;
use App\Support\StripeEnvironment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesFakeStripe;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * Local development and production can share one Stripe sandbox, so every
 * Stripe object we create is tagged with its environment, and the webhook
 * ignores events about another environment's objects (ADR 0015).
 */
class StripeEnvironmentTest extends TestCase
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
        $this->post('/employer/purchases', ['purchase_amount' => '250']);
        $this->purchase = CreditPurchase::latest('id')->firstOrFail();
        $this->post("/employer/purchases/{$this->purchase->id}/pay");
        $this->purchase->refresh();
        auth()->logout();
    }

    private function payment(array $metadata): array
    {
        return [
            'id' => 'pi_test_env', 'object' => 'payment_intent', 'status' => 'succeeded',
            'amount' => 25000, 'amount_received' => 25000, 'currency' => 'usd', 'latest_charge' => 'ch_test_env',
            'metadata' => ['lg_purchase_token' => $this->purchase->payment_token] + $metadata,
        ];
    }

    public function test_the_tag_names_the_environment_and_a_short_instance_id_that_follows_the_app_key(): void
    {
        $tag = StripeEnvironment::tag();
        $this->assertSame('testing', $tag['lg_env']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $tag['lg_instance']);
        $this->assertStringNotContainsString($tag['lg_instance'], (string) config('app.key'));

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->assertNotSame($tag['lg_instance'], StripeEnvironment::instance(), 'Another server, another id');
    }

    public function test_checkout_payments_are_tagged_with_the_environment_and_purchase(): void
    {
        [$session] = $this->stripe->callsOf('checkout');

        foreach ([$session['metadata'], $session['payment_intent_data']['metadata']] as $metadata) {
            $this->assertSame(StripeEnvironment::SAME, StripeEnvironment::of($metadata));
            $this->assertSame((string) $this->purchase->id, $metadata['lg_purchase_id']);
        }
    }

    public function test_connected_accounts_and_transfers_are_tagged(): void
    {
        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $this->payment(StripeEnvironment::tag())))
            ->assertOk()->assertJson(['outcome' => 'paid: '.$this->purchase->reference]);

        $merchant = Merchant::where('name', 'Farragut Grind')->firstOrFail();
        $onboarding = app(StripeOnboarding::class);
        $onboarding->link($merchant);
        [$account] = $this->stripe->callsOf('account');
        $this->assertSame(StripeEnvironment::SAME, StripeEnvironment::of($account['metadata']));
        $this->assertSame((string) $merchant->id, $account['metadata']['lg_merchant_id']);

        $onboarding->apply($merchant->refresh(), $this->stripe->completeOnboarding($merchant->stripe_account_id));
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(10, 'clear'));
        $redemption = app(RedemptionService::class)->redeem(Employee::orderBy('id')->firstOrFail(), $merchant, 800);

        [$transfer] = $this->stripe->callsOf('transfer');
        $this->assertSame(StripeEnvironment::SAME, StripeEnvironment::of($transfer['metadata']));
        $this->assertSame((string) $redemption->id, $transfer['metadata']['lg_redemption_id']);
    }

    public function test_a_payment_tagged_for_another_environment_is_ignored_and_writes_nothing(): void
    {
        $before = [CreditLedgerEntry::count(), StripeEvent::count()];

        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $this->payment(['lg_env' => 'local', 'lg_instance' => 'abcd1234'])))
            ->assertOk()->assertJson(['received' => true, 'outcome' => 'ignored: other environment']);

        $this->assertTrue($this->purchase->fresh()->isPending());
        $this->assertSame($before, [CreditLedgerEntry::count(), StripeEvent::count()]);
    }

    public function test_the_same_env_name_from_another_server_is_still_another_environment(): void
    {
        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $this->payment(['lg_env' => 'testing', 'lg_instance' => 'ffffffff'])))
            ->assertOk()->assertJson(['outcome' => 'ignored: other environment']);

        $this->assertTrue($this->purchase->fresh()->isPending());
    }

    public function test_transfer_and_account_events_from_another_environment_change_nothing(): void
    {
        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $this->payment(StripeEnvironment::tag())))->assertOk();
        $merchant = Merchant::where('name', 'Farragut Grind')->firstOrFail();
        app(StripeOnboarding::class)->link($merchant);
        app(StripeOnboarding::class)->apply($merchant->refresh(), $this->stripe->completeOnboarding($merchant->stripe_account_id));
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(10, 'clear'));
        app(RedemptionService::class)->redeem(Employee::orderBy('id')->firstOrFail(), $merchant, 800);
        $payout = MerchantPayout::stripe()->latest('id')->firstOrFail();
        $before = [CreditLedgerEntry::count(), (int) CreditLedgerEntry::sum('amount_cents'), StripeEvent::count()];
        $other = ['lg_env' => 'local', 'lg_instance' => 'abcd1234'];

        $this->postWebhook($this->stripeEvent('transfer.reversed', ['id' => $payout->stripe_transfer_id, 'object' => 'transfer', 'amount' => 800, 'metadata' => ['lg_payout_key' => $payout->idempotency_key] + $other]))
            ->assertOk()->assertJson(['outcome' => 'ignored: other environment']);
        $this->postWebhook($this->stripeEvent('account.updated', ['id' => $merchant->stripe_account_id, 'object' => 'account', 'details_submitted' => false, 'metadata' => $other]))
            ->assertOk()->assertJson(['outcome' => 'ignored: other environment']);

        $this->assertSame(MerchantPayout::STATUS_SENT, $payout->fresh()->status);
        $this->assertTrue($merchant->fresh()->isStripeOnboarded());
        $this->assertSame($before, [CreditLedgerEntry::count(), (int) CreditLedgerEntry::sum('amount_cents'), StripeEvent::count()]);
    }

    public function test_untagged_legacy_events_are_still_handled_by_this_databases_own_ids(): void
    {
        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $this->payment([])))
            ->assertOk()->assertJson(['outcome' => 'paid: '.$this->purchase->reference]);
    }
}
