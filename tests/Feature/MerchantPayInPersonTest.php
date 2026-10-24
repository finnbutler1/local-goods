<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Controllers\Merchant\PayCodeController;
use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\PayCode;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\PayCodes;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * The merchant's side of paying in person (ADR 0026): Enter pay code, check
 * the amount and items with the customer's initials only, then Confirm
 * payment or Decline.
 */
class MerchantPayInPersonTest extends TestCase
{
    use RefreshDatabase;

    private FakeClaudeClient $claude;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'America/New_York'));
        $this->seed(DatabaseSeeder::class);
        $this->claude = FakeClaudeClient::verdict(5, 'clear');
        $this->app->instance(ClaudeClient::class, $this->claude);
        $this->signIn(Role::Merchant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function grind(): Merchant
    {
        return Merchant::where('name', 'Farragut Grind')->firstOrFail();
    }

    private function jordan(): Employee
    {
        return Employee::orderBy('id')->firstOrFail();
    }

    private function codeAtGrind(int $cents = 850, array $items = []): PayCode
    {
        return app(PayCodes::class)->issue($this->jordan(), $this->grind(), $cents, $items);
    }

    public function test_the_dashboard_has_a_prominent_pay_code_field(): void
    {
        $this->get('/merchant')->assertOk()
            ->assertSeeInOrder(['Take a payment', 'Enter pay code', 'Check code', 'Owed to you now'])
            ->assertSee('id="pay_code" name="pay_code" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="7"', false)
            ->assertSee('data-busy="Checking the code…"', false);
    }

    public function test_after_a_good_code_the_cashier_sees_the_amount_items_and_initials_only(): void
    {
        $latte = $this->grind()->products()->where('name', 'Latte')->firstOrFail();
        $payCode = $this->codeAtGrind(2 * $latte->price_cents, [['merchant_product_id' => $latte->id, 'name' => 'Latte', 'unit_price_cents' => $latte->price_cents, 'quantity' => 2]]);

        $this->post(route('merchant.pay-code.lookup'), ['pay_code' => strtolower(substr($payCode->code, 0, 3).' '.substr($payCode->code, 3))])
            ->assertRedirect(route('merchant.dashboard').'#take-payment')
            ->assertSessionHas(PayCodeController::SESSION_KEY, $payCode->id);

        $html = $this->get('/merchant')->assertOk()
            ->assertSeeInOrder(['Amount', '$11.50', 'Items', '2× Latte', 'Customer', 'J.R.', 'Code expires', '12:10 PM', 'Confirm payment', 'Decline', 'Enter a different code'])
            ->assertSee('data-busy="Checking with Redemption Fraud Detection…"', false)
            ->getContent();
        $this->assertStringNotContainsString('Jordan', $html);
        $this->assertStringNotContainsString('Rivera', $html);
        $this->assertStringNotContainsString($payCode->code, $html);
        $this->assertStringNotContainsString($payCode->displayCode(), $html);
        // Nothing has moved yet.
        $this->assertSame(PayCode::STATUS_ACTIVE, $payCode->fresh()->status);
    }

    public function test_confirming_makes_an_in_person_redemption_and_shows_it_done(): void
    {
        $payCode = $this->codeAtGrind();
        $this->post(route('merchant.pay-code.lookup'), ['pay_code' => $payCode->code]);

        $this->post(route('merchant.pay-code.confirm', $payCode))->assertRedirect(route('merchant.dashboard').'#take-payment');

        $redemption = Redemption::latest('id')->firstOrFail();
        $this->assertSame(Redemption::CHANNEL_IN_PERSON, $redemption->channel);
        $this->assertSame(Redemption::STATUS_APPROVED, $redemption->status);
        $this->assertSame($this->jordan()->id, $redemption->employee_id);
        $this->assertCount(1, $this->claude->calls);
        $this->get('/merchant')->assertOk()->assertSeeInOrder(['Payment confirmed', '$8.50 · J.R.', 'hand over the order', 'Enter pay code']);
    }

    public function test_a_held_payment_shows_neutral_words_and_the_under_review_badge(): void
    {
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(90, 'hold'));
        $payCode = $this->codeAtGrind();
        $this->post(route('merchant.pay-code.lookup'), ['pay_code' => $payCode->code]);
        $this->post(route('merchant.pay-code.confirm', $payCode));

        $card = html_entity_decode((string) str($this->get('/merchant')->assertOk()->getContent())->after('data-pay-result')->before('</section>'), ENT_QUOTES);
        $this->assertStringContainsString('Payment not completed yet', $card);
        $this->assertStringContainsString("It's being held for a quick review, and no money moves until a BID admin decides.", $card);
        $this->assertStringContainsString('Under review', $card);
        foreach (['fraud', 'suspic'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $card);
        }
    }

    public function test_confirm_only_works_on_the_code_just_looked_up(): void
    {
        $payCode = $this->codeAtGrind();

        $this->post(route('merchant.pay-code.confirm', $payCode))->assertSessionHasErrors(['pay_code' => PayCodes::NOT_VALID]);
        $this->post(route('merchant.pay-code.decline', $payCode))->assertSessionHasErrors(['pay_code' => PayCodes::NOT_VALID]);
        $this->assertSame(PayCode::STATUS_ACTIVE, $payCode->fresh()->status);
        $this->assertSame(0, Redemption::where('channel', Redemption::CHANNEL_IN_PERSON)->where('scored_by', '!=', 'seed')->count());
    }

    public function test_another_merchant_gets_code_not_valid_and_nothing_else(): void
    {
        $payCode = $this->codeAtGrind();
        $deli = Merchant::where('name', 'Press Room Deli')->firstOrFail();
        $deliUser = User::factory()->role(Role::Merchant)->create();
        $deli->update(['user_id' => $deliUser->id]);

        $this->actingAs($deliUser)->post(route('merchant.pay-code.lookup'), ['pay_code' => $payCode->code])
            ->assertSessionHasErrors(['pay_code' => PayCodes::NOT_VALID]);
        $this->actingAs($deliUser)->post(route('merchant.pay-code.lookup'), ['pay_code' => 'ZZZZZZ'])
            ->assertSessionHasErrors(['pay_code' => PayCodes::NOT_VALID]);

        // Even with the id, another merchant can't confirm it.
        $this->actingAs($deliUser)->withSession([PayCodeController::SESSION_KEY => $payCode->id])
            ->post(route('merchant.pay-code.confirm', $payCode))->assertSessionHasErrors(['pay_code' => PayCodes::NOT_VALID]);
        $this->assertSame(PayCode::STATUS_ACTIVE, $payCode->fresh()->status);
    }

    public function test_five_misses_lock_out_the_merchant_for_a_minute(): void
    {
        $payCode = $this->codeAtGrind();

        foreach (range(2, 6) as $i) {
            $this->post(route('merchant.pay-code.lookup'), ['pay_code' => "ZZZZZ{$i}"])->assertSessionHasErrors(['pay_code' => PayCodes::NOT_VALID]);
        }
        $this->post(route('merchant.pay-code.lookup'), ['pay_code' => $payCode->code])->assertSessionHasErrors(['pay_code' => PayCodes::TOO_MANY_TRIES]);

        Carbon::setTestNow(now()->addSeconds(61));
        $this->post(route('merchant.pay-code.lookup'), ['pay_code' => $payCode->code])->assertSessionHasNoErrors();
    }

    public function test_a_typed_code_is_never_sent_back_to_the_page(): void
    {
        $this->from('/merchant')->post(route('merchant.pay-code.lookup'), ['pay_code' => 'ZZZ 999']);
        $this->assertNull(session()->getOldInput('pay_code'));

        $html = $this->get('/merchant')->assertOk()->assertSee(PayCodes::NOT_VALID)->assertSee('aria-invalid="true"', false)->getContent();
        $this->assertStringNotContainsString('ZZZ 999', $html);
    }

    public function test_declining_and_entering_a_different_code(): void
    {
        $payCode = $this->codeAtGrind();
        $this->post(route('merchant.pay-code.lookup'), ['pay_code' => $payCode->code]);
        $this->post(route('merchant.pay-code.clear'))->assertSessionMissing(PayCodeController::SESSION_KEY);
        $this->get('/merchant')->assertSee('Enter pay code')->assertDontSee('Confirm payment');
        $this->assertSame(PayCode::STATUS_ACTIVE, $payCode->fresh()->status);

        $entries = CreditLedgerEntry::count();
        $this->post(route('merchant.pay-code.lookup'), ['pay_code' => $payCode->code]);
        $this->post(route('merchant.pay-code.decline', $payCode));

        $this->assertSame(PayCode::STATUS_CANCELLED, $payCode->fresh()->status);
        $this->assertSame(PayCode::BY_MERCHANT, $payCode->fresh()->cancelled_by);
        $this->assertSame($entries, CreditLedgerEntry::count());
    }

    public function test_an_expired_code_drops_off_the_review_card(): void
    {
        $payCode = $this->codeAtGrind();
        $this->post(route('merchant.pay-code.lookup'), ['pay_code' => $payCode->code]);
        Carbon::setTestNow(now()->addMinutes(11));

        $this->get('/merchant')->assertOk()->assertSee('Enter pay code')->assertDontSee('Confirm payment');
        $this->post(route('merchant.pay-code.confirm', $payCode))->assertSessionHasErrors(['pay_code' => PayCodes::NOT_VALID]);
    }

    public function test_judges_see_a_sample_and_cannot_take_payments(): void
    {
        $payCode = $this->codeAtGrind();
        $judge = $this->signIn(Role::Judge);

        $this->actingAs($judge)->followingRedirects()->get(route('judge.view-as', 'merchant'))->assertOk()
            ->assertSee('Take a payment')
            ->assertSee("Read-only: judges can't take payments.", false)
            ->assertSee('Sample, after a code is checked');
        $this->actingAs($judge)->post(route('merchant.pay-code.lookup'), ['pay_code' => $payCode->code])->assertForbidden();
        $this->actingAs($judge)->withSession([PayCodeController::SESSION_KEY => $payCode->id])
            ->post(route('merchant.pay-code.confirm', $payCode))->assertForbidden();
        $this->assertSame(PayCode::STATUS_ACTIVE, $payCode->fresh()->status);
    }
}
