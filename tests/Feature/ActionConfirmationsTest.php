<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CreditPurchase;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Services\Ai\ClaudeClient;
use App\Services\CreditLedger;
use App\Services\Payments\StripeOnboarding;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesFakeStripe;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * Every action ends with a toast that says exactly what happened: the
 * amount, the place, and the employee by initials only (never their name).
 * After an approval it says what happened to the merchant's money by payout
 * channel, and never "paid" unless a Stripe test transfer was sent.
 */
class ActionConfirmationsTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeStripe;

    private Employer $employer;

    private Employee $jordan;

    private Merchant $grind;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->employer = Employer::firstOrFail();
        $this->jordan = Employee::orderBy('id')->firstOrFail();
        $this->grind = Merchant::where('name', config('demo.accounts.merchant.business'))->firstOrFail();
    }

    /** The toast's HTML only: everything between the toast region and <main>. */
    private function toast(TestResponse $response, string $tone = 'status'): string
    {
        $html = $response->assertOk()->getContent();
        $start = strpos($html, 'data-toasts');
        $this->assertNotFalse($start, 'No toast on the page');
        $toast = substr($html, $start, strpos($html, '<main') - $start);
        $this->assertStringContainsString("data-toast=\"{$tone}\"", $toast);
        $this->assertStringContainsString('aria-label="Dismiss message"', $toast);

        return html_entity_decode($toast, ENT_QUOTES);
    }

    private function assertNoFullNames(string $toast): void
    {
        foreach (Employee::pluck('display_name') as $name) {
            $this->assertStringNotContainsString($name, $toast);
        }
    }

    private function fund(string $dollars): void
    {
        $this->post('/employer/purchases', ['purchase_amount' => $dollars]);
        $this->post('/employer/purchases/'.CreditPurchase::latest('id')->firstOrFail()->id.'/pay');
    }

    public function test_giving_credits_to_one_person_names_them_by_initials(): void
    {
        $this->employer->update(['monthly_budget_cents' => null]);
        $this->signIn(Role::EmployerAdmin);
        $this->fund('500');

        $toast = $this->toast($this->followingRedirects()->post('/employer/allocations', [
            'mode' => 'individual', 'occasion' => 'monthly', 'employee_id' => $this->jordan->id, 'amount' => '50',
        ]));

        $this->assertStringContainsString('$50.00 sent to J.R. They can spend it now.', $toast);
        $this->assertNoFullNames($toast);
    }

    public function test_giving_credits_to_many_says_each_and_the_total(): void
    {
        $this->employer->update(['monthly_budget_cents' => null]);
        $this->signIn(Role::EmployerAdmin);
        $this->fund('1000');

        $toast = $this->toast($this->followingRedirects()->post('/employer/allocations', [
            'mode' => 'bulk', 'recipients' => 'all', 'occasion' => 'welcome', 'amount' => '10',
        ]));

        $this->assertStringContainsString('$10.00 welcome credit sent to each of 40 employees ($400.00 in all). They can spend it now.', $toast);
        $this->assertNoFullNames($toast);
    }

    public function test_requesting_then_paying_for_credits_in_simulated_mode(): void
    {
        $this->signIn(Role::EmployerAdmin);

        $toast = $this->toast($this->followingRedirects()->post('/employer/purchases', ['purchase_amount' => '500']), 'notice');
        $this->assertStringContainsString('for $500.00 requested. Press "Pay now" to add the credits.', $toast);

        $purchase = CreditPurchase::latest('id')->firstOrFail();
        $toast = $this->toast($this->followingRedirects()->post("/employer/purchases/{$purchase->id}/pay"));
        $this->assertStringContainsString('$500.00 in credits added (simulated, no real money). Ready to give.', $toast);
    }

    public function test_back_from_stripe_checkout_once_paid(): void
    {
        $this->fakeStripe();
        $this->signIn(Role::EmployerAdmin);
        $purchase = $this->stripePurchase(50000, 'ch_test_checkout');

        $toast = $this->toast($this->get('/employer?checkout=returned&purchase='.$purchase->reference));

        $this->assertStringContainsString("\$500.00 in credits added (Stripe test mode, {$purchase->reference}). Ready to give.", $toast);
    }

    public function test_back_from_stripe_checkout_cancelled(): void
    {
        $this->signIn(Role::EmployerAdmin);

        $toast = $this->toast($this->get('/employer?checkout=cancelled&purchase=X'), 'notice');

        $this->assertStringContainsString('Payment cancelled. Nothing was charged', $toast);
    }

    public function test_a_held_redemption_says_no_money_moves_yet(): void
    {
        $toast = $this->toast($this->redeemAsJordan('8', 'hold'), 'notice');

        $this->assertStringContainsString('$8.00 at Farragut Grind held for a quick review: no money moves until a BID admin decides.', $toast);
        $this->assertStringNotContainsString('approved', $toast);
    }

    public function test_denying_names_the_employee_by_initials_and_where_the_credit_went(): void
    {
        $held = $this->heldAtGrind();

        $toast = $this->toast($this->followingRedirects()->post(route('bid-admin.deny', $held), ['reason' => 'Tapped by mistake.', 'denial' => 'no_purchase']));

        $this->assertStringContainsString('Denied $8.00 at Farragut Grind for J.R. No transfer; the credit is back with the employee.', $toast);
        $this->assertNoFullNames($toast);
    }

    public function test_reviewing_one_already_decided_is_an_error_toast(): void
    {
        $held = $this->heldAtGrind();
        $this->post(route('bid-admin.approve', $held), ['reason' => 'Regular customer.']);

        $toast = $this->toast($this->followingRedirects()->post(route('bid-admin.approve', $held), ['reason' => 'Regular customer.']), 'error');

        $this->assertStringContainsString('Nothing changed: this redemption is already approved.', $toast);
    }

    public function test_sending_feedback(): void
    {
        $this->signIn(Role::EmployerAdmin);

        $toast = $this->toast($this->from('/employer')->followingRedirects()->post('/feedback', [
            'page_url' => url('/employer'), 'category' => 'ai_integration', 'rating' => 4,
            'severity' => 'should_fix', 'comment' => 'The chart is hard to read.',
        ]));

        $this->assertStringContainsString('Thanks, your feedback was sent.', $toast);
    }

    /** @return array<string, array{string, string}> */
    public static function channels(): array
    {
        return [
            'simulated' => ['simulated', 'Now owed to the merchant (simulated, no money moved).'],
            'Stripe, sent' => ['sent', 'Stripe test transfer sent.'],
            'Stripe, merchant not onboarded' => ['not_onboarded', "Owed to the merchant through Stripe. No transfer yet: they haven't finished Stripe onboarding."],
            'Stripe, transfer refused' => ['refused', "Owed to the merchant through Stripe. The test transfer couldn't be made yet, so nothing moved."],
        ];
    }

    #[DataProvider('channels')]
    public function test_approving_a_held_redemption_says_what_happened_to_the_money(string $channel, string $sentence): void
    {
        $this->channel($channel);
        $held = $this->heldAtGrind();

        $toast = $this->toast($this->followingRedirects()->post(route('bid-admin.approve', $held), ['reason' => 'Regular customer.']));

        $this->assertStringContainsString("Approved \$8.00 at Farragut Grind for J.R. {$sentence}", $toast);
        $this->assertStringNotContainsString('paid', $toast);
        $this->assertNoFullNames($toast);
    }

    #[DataProvider('channels')]
    public function test_a_redemption_cleared_by_the_ai_says_what_happened_to_the_money(string $channel, string $sentence): void
    {
        $this->channel($channel);

        $toast = $this->toast($this->redeemAsJordan('8', 'clear'));

        $this->assertStringContainsString("\$8.00 at Farragut Grind approved. {$sentence}", $toast);
        $this->assertStringNotContainsString('paid', $toast);
    }

    /** Sets up one payout channel (ADR 0015) for the next approval at Farragut Grind. */
    private function channel(string $channel): void
    {
        if ($channel === 'simulated') {
            return;
        }

        $this->fakeStripe();
        $this->stripePurchase();
        if ($channel !== 'not_onboarded') {
            $this->stripe->accounts['acct_test_FG'] = ['id' => 'acct_test_FG'];
            app(StripeOnboarding::class)->apply($this->grind, $this->stripe->completeOnboarding('acct_test_FG'));
        }
        if ($channel === 'refused') {
            $this->stripe->transferFailures = [['Stripe refused the transfer (fake, for this test).', true]];
        }
    }

    /** A purchase paid by card in Stripe test mode (as the webhook records it). */
    private function stripePurchase(int $cents = 50000, string $charge = 'ch_test_A'): CreditPurchase
    {
        $ledger = app(CreditLedger::class);
        $purchase = $ledger->requestPurchase($this->employer, $cents, 'MPG-STRIPE-'.$charge);
        $ledger->markPurchasePaidByStripe($purchase, 'pi_'.$charge, $charge, $cents, 'usd');

        return $purchase->fresh();
    }

    private function redeemAsJordan(string $dollars, string $decision): TestResponse
    {
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict($decision === 'hold' ? 90 : 10, $decision));
        $this->signIn(Role::Employee);

        return $this->followingRedirects()->post('/employee/redeem', ['merchant_id' => $this->grind->id, 'amount' => $dollars]);
    }

    private function heldAtGrind(): Redemption
    {
        $this->redeemAsJordan('8', 'hold');
        $this->followRedirects = false;
        $this->signIn(Role::BidAdmin);

        return tap(Redemption::latest('id')->firstOrFail(), fn ($r) => $this->assertTrue($r->isUnderReview()));
    }
}
