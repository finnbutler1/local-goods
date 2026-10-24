<?php

namespace Tests\Feature;

use App\Enums\DenialReason;
use App\Enums\Role;
use App\Exceptions\RedemptionAlreadyReviewed;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Models\PayCode;
use App\Models\Redemption;
use App\Models\RedemptionDecision;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\CreditLedger;
use App\Services\PayCodes;
use App\Services\Payments\MerchantPayouts;
use App\Services\Payments\StripeOnboarding;
use App\Services\Payments\TransferGate;
use App\Services\RedemptionReviewService;
use App\Services\RedemptionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\Concerns\UsesFakeStripe;
use Tests\Fakes\FakeClaudeClient;
use Tests\Support\AccessibilityAudit;
use Tests\TestCase;

/**
 * "Cancel this payment" on a held in-person payment (ADR 0026): the
 * customer paid another way, so the hold must never be approved later. It
 * goes through the same return path as a "no purchase happened" denial
 * (one ledger entry back to the employee, no transfer), but is recorded as
 * a cancel by the merchant (or a BID or platform admin), not as a fraud
 * denial. The employee can't cancel a held payment: they ask the shop.
 */
class HeldPaymentCancelTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeStripe;

    private Employee $jordan;

    private Merchant $grind;

    private User $merchantUser;

    private User $employeeUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeStripe();
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(90, 'hold', 'Round amount.'));
        $this->grind = Merchant::where('name', config('demo.accounts.merchant.business'))->firstOrFail();
        $this->employeeUser = $this->signIn(Role::Employee);
        $this->merchantUser = $this->signIn(Role::Merchant);
        $this->jordan = $this->employeeUser->employee;

        // Stripe-paid credits and an onboarded merchant, so an approval would really transfer.
        $ledger = app(CreditLedger::class);
        $purchase = $ledger->requestPurchase(Employer::firstOrFail(), 300000, 'MPG-STRIPE-CANCEL');
        $purchase->update(['payment_token' => 'tok-cancel']);
        $ledger->markPurchasePaidByStripe($purchase, 'pi_test_cancel', 'ch_test_cancel', 300000, 'usd');
        $ledger->allocate(Employer::firstOrFail(), $this->jordan, 10000);
        $this->stripe->accounts['acct_test_cancel'] = ['id' => 'acct_test_cancel'];
        app(StripeOnboarding::class)->apply($this->grind, $this->stripe->completeOnboarding('acct_test_cancel'));
    }

    private function heldInPerson(int $cents = 4000): Redemption
    {
        $codes = app(PayCodes::class);
        $held = $codes->confirm($codes->issue($this->jordan->fresh(), $this->grind->fresh(), $cents), $this->grind->fresh());
        $this->assertTrue($held->isUnderReview());

        return $held;
    }

    private function admin(): User
    {
        return User::where('role', Role::BidAdmin)->first() ?? User::factory()->role(Role::BidAdmin)->create(['name' => 'Sam Taylor']);
    }

    /** Paid purchases − payouts sent or simulated − amounts under review = sum of every entry (ADR 0012). */
    private function assertLedgerInvariant(): void
    {
        $purchased = (int) CreditPurchase::whereIn('status', CreditPurchase::PAID_STATUSES)->sum('amount_cents');
        $paidOut = (int) MerchantPayout::paid()->sum('amount_cents');
        $held = (int) Redemption::where('status', Redemption::STATUS_UNDER_REVIEW)->sum('amount_cents');
        $this->assertSame($purchased - $paidOut - $held, (int) CreditLedgerEntry::sum('amount_cents'));
    }

    public function test_the_merchant_cancels_and_the_credits_come_back_exactly_once(): void
    {
        $held = $this->heldInPerson();
        $balance = $this->jordan->balanceCents();
        $entries = CreditLedgerEntry::count();

        $this->actingAs($this->merchantUser)->post(route('merchant.redemption.cancel', $held))
            ->assertRedirect(route('merchant.dashboard').'#take-payment')
            ->assertSessionHas('status', "Payment cancelled: \$40.00 back to J.R.'s credits at Farragut Grind. You aren't owed it.");
        // A second press, or a second tab, changes nothing.
        $this->actingAs($this->merchantUser)->post(route('merchant.redemption.cancel', $held))
            ->assertSessionHas('error', 'Nothing changed: this payment was already decided.');

        $this->assertSame($balance + 4000, $this->jordan->balanceCents());
        $this->assertSame($entries + 1, CreditLedgerEntry::count());
        $entry = CreditLedgerEntry::latest('id')->firstOrFail();
        $this->assertSame(CreditLedgerEntry::TYPE_REVIEW_REJECTED, $entry->type);
        $this->assertSame('Cancelled at the counter by the merchant: $40.00 returned from Farragut Grind', $entry->description);

        $held->refresh();
        $this->assertSame(Redemption::STATUS_REJECTED, $held->status);
        $this->assertTrue($held->isCancelled());
        $this->assertSame('Cancelled by merchant', $held->cancelledLabel());
        $decision = RedemptionDecision::where('redemption_id', $held->id)->sole();
        $this->assertSame(RedemptionDecision::CANCEL, $decision->decision);
        $this->assertSame('employee', $decision->credit_returned_to);
        $this->assertSame(Role::Merchant->value, $decision->decided_by_role);
        $this->assertLedgerInvariant();
    }

    public function test_the_employee_cannot_cancel_a_held_payment_and_is_told_the_shop_can(): void
    {
        $held = $this->heldInPerson();
        $payCode = PayCode::where('redemption_id', $held->id)->sole();
        $balance = $this->jordan->balanceCents();

        $this->actingAs($this->employeeUser)->post(route('merchant.redemption.cancel', $held))->assertForbidden();
        $this->actingAs($this->employeeUser)->post("/employee/redemptions/{$held->id}/cancel")->assertNotFound();
        $this->assertFalse(Route::has('employee.redemption.cancel'));
        $this->assertFalse(RedemptionDecision::mayCancel($this->employeeUser, $held));

        $this->actingAs($this->employeeUser)->get('/employee?pay_code='.$payCode->id)->assertOk()
            ->assertSee("Your payment is being held for a quick review. Your credits are set aside, and come back to you if it isn't approved. If you pay another way, the shop can cancel this payment and your credits come back.", false)
            ->assertDontSee('Cancel this payment');
        $this->assertTrue($held->fresh()->isUnderReview());
        $this->assertSame($balance, $this->jordan->balanceCents());

        $this->expectException(LogicException::class);
        app(RedemptionReviewService::class)->cancel($held, $this->employeeUser);
    }

    public function test_an_unused_pay_code_can_still_be_cancelled_by_the_employee(): void
    {
        $payCode = app(PayCodes::class)->issue($this->jordan->fresh(), $this->grind, 500);

        $this->actingAs($this->employeeUser)->post(route('employee.pay-code.cancel', $payCode))
            ->assertSessionHas('status', 'Pay code cancelled. Nothing was charged.');
        $this->assertSame(PayCode::STATUS_CANCELLED, $payCode->fresh()->status);
    }

    public function test_a_bid_admin_may_cancel_and_it_says_so(): void
    {
        $held = $this->heldInPerson();
        $balance = $this->jordan->balanceCents();

        app(RedemptionReviewService::class)->cancel($held, $this->admin());

        $this->assertSame($balance + 4000, $this->jordan->balanceCents());
        $this->assertSame('Cancelled by BID admin', $held->fresh()->cancelledLabel());
        $this->assertSame(Role::BidAdmin->value, RedemptionDecision::where('redemption_id', $held->id)->sole()->decided_by_role);
        $this->assertLedgerInvariant();
    }

    public function test_a_cancelled_hold_can_never_be_approved_or_denied_and_never_transfers(): void
    {
        $held = $this->heldInPerson();
        app(RedemptionReviewService::class)->cancel($held, $this->merchantUser);

        $this->signIn(Role::BidAdmin);
        $this->post(route('bid-admin.approve', $held), ['reason' => 'Checked with the merchant.'])
            ->assertSessionHas('error', 'Nothing changed: this redemption is already cancelled at the counter.');
        $this->post(route('bid-admin.deny', $held), ['reason' => 'Checked it.', 'denial' => 'no_purchase'])->assertSessionHas('error');
        app(MerchantPayouts::class)->send($this->grind->fresh());

        $this->assertSame(Redemption::STATUS_REJECTED, $held->fresh()->status);
        $this->assertSame(1, RedemptionDecision::where('redemption_id', $held->id)->count());
        $this->assertSame(0, MerchantPayout::where('redemption_id', $held->id)->count());
        $this->assertSame(0, CreditLedgerEntry::where('redemption_id', $held->id)->where('type', CreditLedgerEntry::TYPE_MERCHANT_EARNED)->count());
        $this->assertSame([], $this->stripe->callsOf('transfer'));
        $this->assertFalse(app(TransferGate::class)->allows($held->fresh()));
    }

    public function test_only_a_payment_still_held_can_be_cancelled(): void
    {
        $this->expectException(RedemptionAlreadyReviewed::class);

        $held = $this->heldInPerson();
        app(RedemptionReviewService::class)->deny($held, $this->admin(), 'Checked with the merchant.', DenialReason::NoPurchase);

        app(RedemptionReviewService::class)->cancel($held->fresh(), $this->merchantUser);
    }

    public function test_an_admin_decision_first_means_the_cancel_is_refused(): void
    {
        $approved = $this->heldInPerson();
        app(RedemptionReviewService::class)->approve($approved, $this->admin(), 'Regular customer.');
        $this->assertCount(1, $this->stripe->callsOf('transfer'));

        $this->actingAs($this->merchantUser)->post(route('merchant.redemption.cancel', $approved))->assertSessionHas('error');

        $this->assertTrue($approved->fresh()->isApproved());
        $this->assertCount(1, $this->stripe->callsOf('transfer'));
        $this->actingAs($this->merchantUser)->get('/merchant')->assertDontSee(route('merchant.redemption.cancel', $approved), false);
    }

    public function test_a_to_go_hold_cannot_be_cancelled_this_way(): void
    {
        $toGo = app(RedemptionService::class)->redeem($this->jordan->fresh(), $this->grind, 4000);
        $this->assertTrue($toGo->isUnderReview());

        $this->actingAs($this->merchantUser)->post(route('merchant.redemption.cancel', $toGo))->assertNotFound();
        $this->expectException(LogicException::class);
        app(RedemptionReviewService::class)->cancel($toGo, $this->merchantUser);
    }

    public function test_only_its_own_merchant_can_cancel_from_the_dashboard(): void
    {
        $held = $this->heldInPerson();
        $otherMerchant = User::factory()->role(Role::Merchant)->create();
        Merchant::where('name', 'Press Room Deli')->firstOrFail()->update(['user_id' => $otherMerchant->id]);

        $this->actingAs($otherMerchant)->post(route('merchant.redemption.cancel', $held))->assertNotFound();
        $this->actingAs($this->admin())->post(route('merchant.redemption.cancel', $held))->assertForbidden();

        $this->assertTrue($held->fresh()->isUnderReview());
        $this->expectException(LogicException::class);
        app(RedemptionReviewService::class)->cancel($held, $otherMerchant);
    }

    public function test_judges_cannot_cancel_and_see_no_cancel_button(): void
    {
        $held = $this->heldInPerson();
        $judge = $this->signIn(Role::Judge);

        $this->actingAs($judge)->post(route('merchant.redemption.cancel', $held))->assertForbidden();
        $this->actingAs($judge)->followingRedirects()->get(route('judge.view-as', 'merchant'))->assertOk()
            ->assertSee('Held at the counter')
            ->assertDontSee('Cancel this payment');
        $this->assertTrue($held->fresh()->isUnderReview());
    }

    public function test_fraud_review_shows_cancelled_by_and_drops_it_from_the_queue(): void
    {
        $held = $this->heldInPerson();
        $this->signIn(Role::BidAdmin);
        $this->get('/bid-admin')->assertOk()->assertSee(route('bid-admin.approve', $held), false);
        $queued = Redemption::where('status', Redemption::STATUS_UNDER_REVIEW)->count();

        app(RedemptionReviewService::class)->cancel($held, $this->merchantUser);

        $this->assertSame($queued - 1, Redemption::where('status', Redemption::STATUS_UNDER_REVIEW)->count());
        $card = (string) str($this->get('/bid-admin')->assertOk()->getContent())->after('id="redemption-'.$held->id.'"')->before('</li>');
        $this->assertStringContainsString('Cancelled by merchant', $card);
        $this->assertStringContainsString('cancelled at the counter by Merchant', $card);
        $this->assertStringContainsString('not a fraud denial', $card);
        $this->assertStringNotContainsString(route('bid-admin.approve', $held), $card);
        $this->assertStringNotContainsString('Denied', $card);
    }

    public function test_cancels_are_not_fraud_eval_or_adjudication_data(): void
    {
        $cases = hash_file('sha256', config('fraud.eval_cases_path'));
        app(RedemptionReviewService::class)->cancel($this->heldInPerson(), $this->merchantUser);

        foreach ([...glob(app_path('Services/Fraud/FraudEval*.php')), ...glob(app_path('Services/Adjudication/*.php')), app_path('Http/Controllers/FraudEvalController.php'), app_path('Http/Controllers/AdjudicateController.php')] as $file) {
            $source = file_get_contents($file);
            foreach (['RedemptionDecision', 'redemption_decisions', 'CANCEL', 'channel'] as $word) {
                $this->assertStringNotContainsString($word, $source, basename($file));
            }
        }
        $this->assertSame($cases, hash_file('sha256', config('fraud.eval_cases_path')));
        $this->get('/eval')->assertOk()->assertDontSee('Cancelled at the counter');
    }

    public function test_the_held_screens_with_cancel_buttons_pass_the_accessibility_checks(): void
    {
        $held = $this->heldInPerson();
        $payCode = \App\Models\PayCode::where('redemption_id', $held->id)->sole();

        $problems = array_filter([
            'merchant, held at the counter' => AccessibilityAudit::problems($this->actingAs($this->merchantUser)->get('/merchant')->assertOk()->assertSee('Held at the counter')->getContent()),
            'wallet, held confirmation' => AccessibilityAudit::problems($this->actingAs($this->employeeUser)->get('/employee?pay_code='.$payCode->id)->assertOk()->assertSee('the shop can cancel this payment')->getContent()),
        ]);

        $this->assertSame([], $problems);
    }
}
