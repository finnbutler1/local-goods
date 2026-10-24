<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Services\Ai\ClaudeClient;
use App\Services\CreditLedger;
use App\Services\PayCodes;
use App\Services\Payments\StripeOnboarding;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesFakeStripe;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * "To go" or "In person" (ADR 0026) is shown wherever a redemption is:
 * the wallet's receipt, recent list and history, the merchant's list, Fraud
 * review and reconciliation. Display only.
 */
class ChannelDisplayTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeStripe;

    private Redemption $inPerson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeStripe();
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(5, 'clear'));
        $this->signIn(Role::Employee);
        $this->signIn(Role::Merchant);

        // Stripe-paid credits and an onboarded merchant, so the in-person payment is a sent transfer.
        $ledger = app(CreditLedger::class);
        $purchase = $ledger->requestPurchase(Employer::firstOrFail(), 100000, 'MPG-STRIPE-CHANNEL');
        $ledger->markPurchasePaidByStripe($purchase, 'pi_test_channel', 'ch_test_channel', 100000, 'usd');
        $grind = Merchant::where('name', 'Farragut Grind')->firstOrFail();
        $this->stripe->accounts['acct_test_channel'] = ['id' => 'acct_test_channel'];
        app(StripeOnboarding::class)->apply($grind, $this->stripe->completeOnboarding('acct_test_channel'));
        $jordan = Employee::orderBy('id')->firstOrFail();
        $ledger->allocate(Employer::firstOrFail(), $jordan, 5000);

        $codes = app(PayCodes::class);
        $this->inPerson = $codes->confirm($codes->issue($jordan->fresh(), $grind->fresh(), 937), $grind->fresh());
    }

    public function test_the_wallet_shows_it_on_the_receipt_the_recent_list_and_the_history(): void
    {
        $this->signIn(Role::Employee);
        $payCode = \App\Models\PayCode::where('redemption_id', $this->inPerson->id)->sole();

        $this->get('/employee?pay_code='.$payCode->id)->assertOk()
            ->assertSeeInOrder(['How', 'In person'])
            ->assertSeeInOrder(['Recent redemptions', 'Farragut Grind', '· In person'])
            ->assertSeeInOrder(['Transaction history', 'Redemption at Farragut Grind', 'In person']);
        $this->get('/employee')->assertOk()->assertSee('· To go');
    }

    public function test_the_merchant_sees_it_next_to_each_redemption(): void
    {
        $this->signIn(Role::Merchant);

        $this->get('/merchant')->assertOk()->assertSeeInOrder(['Redemptions received', 'J.R.', '· In person']);
    }

    public function test_fraud_review_says_it_was_not_sent_to_the_ai(): void
    {
        $this->signIn(Role::BidAdmin);

        $card = (string) str($this->get('/bid-admin')->assertOk()->getContent())->after('id="redemption-'.$this->inPerson->id.'"')->before('</li>');
        $this->assertStringContainsString('Channel: In person', $card);
        $this->assertStringContainsString('(not sent to Redemption Fraud Detection)', $card);
    }

    public function test_reconciliation_splits_sent_transfers_by_channel_for_information_only(): void
    {
        $this->assertCount(1, $this->stripe->callsOf('transfer'));
        $this->signIn(Role::PlatformAdmin);

        $this->get('/admin/reconciliation')->assertOk()
            ->assertSee('Sent transfers by how the customer paid:')
            ->assertSee('0 to go ($0.00), 1 in person ($9.37).')
            ->assertSee("Display only: the check above doesn't use it.", false);
    }
}
