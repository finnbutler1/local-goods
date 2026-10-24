<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\PayCode;
use App\Models\Redemption;
use App\Services\Ai\ClaudeClient;
use App\Services\PayCodes;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * The wallet's two tabs (ADR 0026): Order to go (today's flow, the default)
 * and Pay in person (get a code, wait for the merchant).
 */
class PayInPersonWalletTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'America/New_York'));
        $this->seed(DatabaseSeeder::class);
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(5, 'clear'));
        $this->signIn(Role::Employee);
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

    public function test_the_wallet_has_two_real_tabs_and_order_to_go_is_the_default(): void
    {
        $html = $this->get('/employee')->getContent();
        $this->assertMatchesRegularExpression('/id="panel-to-go" aria-labelledby="tab-to-go" tabindex="0" class="mt-6 space-y-6"\s*>/', $html);
        $this->assertMatchesRegularExpression('/id="panel-in-person" aria-labelledby="tab-in-person" tabindex="0" class="mt-6"\s+hidden\s*>/', $html);

        $this->get('/employee')->assertOk()
            ->assertSee('role="tablist"', false)
            ->assertSeeInOrder(['id="tab-to-go"', 'aria-selected="true"', 'id="tab-in-person"', 'aria-selected="false"'], false)
            ->assertSeeInOrder(['Order to go', 'Pay in person', 'Redeem credits', 'Where you can spend'])
            ->assertSee('Where are you paying?');

        $this->get('/employee?tab=in-person')->assertOk()
            ->assertSeeInOrder(['id="tab-to-go"', 'aria-selected="false"', 'id="tab-in-person"', 'aria-selected="true"'], false)
            ->assertSee('Where are you paying?');
        $this->assertMatchesRegularExpression('/id="panel-to-go" aria-labelledby="tab-to-go" tabindex="0" class="mt-6 space-y-6"\s+hidden\s*>/', $this->get('/employee?tab=in-person')->getContent());
    }

    public function test_the_tabs_work_from_the_keyboard(): void
    {
        $js = File::get(resource_path('js/app.js'));

        $this->assertStringContainsString("document.querySelectorAll('[data-tabs]')", $js);
        foreach (['ArrowRight', 'ArrowLeft', 'Home', 'End', "setAttribute('aria-selected'", 'tabIndex', '.hidden = !on'] as $part) {
            $this->assertStringContainsString($part, $js);
        }
    }

    public function test_picking_a_merchant_shows_its_menu_with_ids_of_its_own(): void
    {
        $latte = $this->grind()->products()->where('name', 'Latte')->firstOrFail();

        $this->get('/employee?tab=in-person&pay_merchant='.$this->grind()->id)->assertOk()
            ->assertSee('Paying Farragut Grind')
            ->assertSee('id="pay-qty-'.$latte->id.'"', false)
            ->assertSee('id="pay-amount"', false)
            ->assertSee('<input type="hidden" name="pay_merchant_id" value="'.$this->grind()->id.'">', false)
            ->assertSee('data-busy="Getting your pay code…"', false)
            ->assertSee('Get pay code')
            // Order to go is untouched: no merchant picked there.
            ->assertDontSee('At Farragut Grind');
    }

    public function test_getting_a_code_shows_it_and_moves_nothing(): void
    {
        $entries = CreditLedgerEntry::count();
        $latte = $this->grind()->products()->where('name', 'Latte')->firstOrFail();

        $this->post('/employee/pay-code', ['pay_merchant_id' => $this->grind()->id, 'items' => [$latte->id => 2], 'amount' => ''])
            ->assertRedirect(route('employee.wallet', ['tab' => 'in-person']).'#pay')
            ->assertSessionHas('status', 'Pay code ready for $11.50 at Farragut Grind. Show it to the cashier.');

        $payCode = PayCode::firstOrFail();
        $this->assertSame($entries, CreditLedgerEntry::count());
        $this->assertSame(2 * $latte->price_cents, $payCode->amount_cents);

        // A live code opens the Pay in person tab on its own.
        $this->get('/employee')->assertOk()
            ->assertSee('id="tab-in-person" href="'.route('employee.wallet', ['tab' => 'in-person']).'#wallet-tabs"'."\n".'                   aria-controls="panel-in-person" aria-selected="true"', false)
            ->assertSee($payCode->displayCode())
            ->assertSee('Pay code: '.$payCode->spokenCode())
            ->assertSee('Show this code to the cashier at Farragut Grind')
            ->assertSee('2× Latte')
            ->assertSee('$11.50')
            ->assertSee('Expires at 12:10 PM')
            ->assertSee('data-pay-code-status-url="'.route('employee.pay-code.status', $payCode).'"', false)
            ->assertSee('Waiting for Farragut Grind to confirm…')
            ->assertSee(route('employee.pay-code.cancel', $payCode), false);
    }

    public function test_the_countdown_is_hidden_from_screen_readers_and_speaks_only_twice(): void
    {
        app(PayCodes::class)->issue($this->jordan(), $this->grind(), 500);

        $html = $this->get('/employee')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<span aria-hidden="true">Expires in <span class="tabular font-semibold" data-countdown>10:00<\/span>/', $html);
        $this->assertStringNotContainsString('role="timer"', $html);
        $js = File::get(resource_path('js/app.js'));
        $this->assertSame(2, substr_count($js, 'announce.textContent ='));
    }

    public function test_the_status_poll_says_only_the_status(): void
    {
        $payCode = app(PayCodes::class)->issue($this->jordan(), $this->grind(), 500);

        $this->getJson(route('employee.pay-code.status', $payCode))->assertOk()->assertExactJson(['status' => 'active']);
        $this->assertStringNotContainsString($payCode->code, $this->getJson(route('employee.pay-code.status', $payCode))->getContent());

        Carbon::setTestNow(now()->addMinutes(11));
        $this->getJson(route('employee.pay-code.status', $payCode))->assertExactJson(['status' => 'expired']);
    }

    public function test_nobody_else_can_poll_or_cancel_my_code(): void
    {
        $other = Employee::orderBy('id')->skip(1)->firstOrFail();
        $payCode = app(PayCodes::class)->issue($other, $this->grind(), 500);

        $this->getJson(route('employee.pay-code.status', $payCode))->assertNotFound();
        $this->post(route('employee.pay-code.cancel', $payCode))->assertNotFound();
        $this->assertSame(PayCode::STATUS_ACTIVE, $payCode->fresh()->status);
    }

    public function test_cancelling_a_code(): void
    {
        $payCode = app(PayCodes::class)->issue($this->jordan(), $this->grind(), 500);

        $this->post(route('employee.pay-code.cancel', $payCode))
            ->assertRedirect(route('employee.wallet', ['tab' => 'in-person']).'#pay')
            ->assertSessionHas('status', 'Pay code cancelled. Nothing was charged.');

        $this->assertSame(PayCode::STATUS_CANCELLED, $payCode->fresh()->status);
        $this->get('/employee?pay_code='.$payCode->id)->assertOk()->assertSee('That pay code was cancelled. Nothing was charged.');
    }

    public function test_after_the_merchant_confirms_the_wallet_shows_the_payment(): void
    {
        $payCode = app(PayCodes::class)->issue($this->jordan(), $this->grind(), 850);
        $redemption = app(PayCodes::class)->confirm($payCode, $this->grind());

        $this->getJson(route('employee.pay-code.status', $payCode))->assertExactJson(['status' => 'used']);
        $this->get('/employee?pay_code='.$payCode->id)->assertOk()
            ->assertSeeInOrder(['Approved', '$8.50', 'Farragut Grind', 'LG-'.str_pad((string) $redemption->id, 6, '0', STR_PAD_LEFT), 'In person', 'Done. Farragut Grind confirmed it at the counter'])
            ->assertSee('data-live-clock', false);
    }

    public function test_a_held_payment_says_so_in_neutral_words(): void
    {
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(88, 'hold'));
        $payCode = app(PayCodes::class)->issue($this->jordan(), $this->grind(), 850);
        app(PayCodes::class)->confirm($payCode, $this->grind());

        $html = $this->get('/employee?pay_code='.$payCode->id)->assertOk()
            ->assertSeeInOrder(['Under review', '$8.50', 'In person', 'Your payment is being held for a quick review. Your credits are set aside, and come back to you if it isn&#039;t approved.'], false)
            ->getContent();
        $confirmation = str($html)->after('id="confirmation-heading"')->before('</section>');
        $this->assertStringNotContainsStringIgnoringCase('fraud', $confirmation);
        $this->assertStringNotContainsStringIgnoringCase('suspic', $confirmation);
    }

    public function test_a_declined_or_expired_code_says_what_happened(): void
    {
        $codes = app(PayCodes::class);
        $declined = $codes->issue($this->jordan(), $this->grind(), 500);
        $codes->decline($declined, $this->grind());
        $this->get('/employee?pay_code='.$declined->id)->assertOk()->assertSee('Farragut Grind declined that pay code. Nothing was charged.');

        $expired = $codes->issue($this->jordan(), $this->grind(), 500);
        Carbon::setTestNow(now()->addMinutes(11));
        $this->get('/employee?pay_code='.$expired->id)->assertOk()->assertSee('That pay code expired before it was used. Nothing was charged.');
    }

    public function test_pay_code_errors_stay_on_their_own_form(): void
    {
        $this->from('/employee')->post('/employee/pay-code', ['pay_merchant_id' => $this->grind()->id, 'amount' => 'abc'])
            ->assertRedirect(route('employee.wallet', ['tab' => 'in-person']).'#pay');

        // Checked on the page, not with assertSessionHasErrorsIn: after that assertion the next request here didn't see the bag.
        $this->get(route('employee.wallet', ['tab' => 'in-person']))->assertOk()
            ->assertSee('id="pay-amount-error"', false)
            ->assertDontSee('id="amount-error"', false)
            ->assertSee('Paying Farragut Grind');
    }

    public function test_more_than_the_balance_is_refused_before_any_code_is_made(): void
    {
        $this->post('/employee/pay-code', ['pay_merchant_id' => $this->grind()->id, 'amount' => '9999'])
            ->assertSessionHasErrorsIn(\App\Http\Controllers\Employee\PayCodeController::ERRORS, ['amount' => 'That is more than the available balance.']);
        $this->assertSame(0, PayCode::count());
    }

    public function test_judges_see_both_tabs_and_a_sample_code_but_cannot_get_one(): void
    {
        $judge = $this->signIn(Role::Judge);

        $this->actingAs($judge)->followingRedirects()->get(route('judge.view-as', 'employee'))->assertOk()
            ->assertSee('Order to go')
            ->assertSee('Pay in person')
            ->assertSee('Sample code screen')
            ->assertSee('K7M 4PQ')
            ->assertSee('Sample pay code: K, 7, M, 4, P, Q');

        $this->actingAs($judge)->get('/employee?tab=in-person&pay_merchant='.$this->grind()->id)->assertOk()
            ->assertSee("Read-only: judges can't get pay codes.", false);
        $this->actingAs($judge)->post('/employee/pay-code', ['pay_merchant_id' => $this->grind()->id, 'amount' => '5'])->assertForbidden();
        $this->assertSame(0, PayCode::count());
        $this->assertSame(0, PayCode::where('code_hash', PayCodes::hash('K7M4PQ'))->count());
    }
}
