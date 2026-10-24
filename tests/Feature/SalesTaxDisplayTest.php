<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Services\Ai\ClaudeClient;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * Where DC sales tax shows (ADR 0027): before paying, on the confirmation,
 * in history and on the pay code, always from the redemption's own snapshot.
 */
class SalesTaxDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'America/New_York'));
        $this->seed(DatabaseSeeder::class);
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(5, 'clear'));
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

    private function latteId(): int
    {
        return $this->grind()->products()->where('name', 'Latte')->value('id');
    }

    public function test_before_paying_each_item_shows_its_tax_in_both_tabs(): void
    {
        $this->signIn(Role::Employee);

        foreach (['/employee?merchant=', '/employee?tab=in-person&pay_merchant='] as $url) {
            $this->get($url.$this->grind()->id)->assertOk()
                ->assertSee('+ DC meals tax 10%')
                ->assertSee('data-tax-category="prepared_food" data-tax-rate="1000" data-tax-label="DC meals tax 10%"', false)
                ->assertSee('Amount includes any tax charged by the shop.')
                ->assertSee('data-basket-lines', false)
                ->assertSee('The shop is responsible for the tax.');
        }
    }

    public function test_an_exempt_item_says_not_taxed(): void
    {
        $this->signIn(Role::Employee);
        $salon = Merchant::where('name', 'K Street Cuts')->firstOrFail();

        $this->get('/employee?merchant='.$salon->id)->assertOk()->assertSee('· not taxed')->assertDontSee('+ DC meals tax');
    }

    public function test_the_confirmation_and_history_show_subtotal_tax_and_total(): void
    {
        $this->signIn(Role::Employee);
        $this->post('/employee/redeem', ['merchant_id' => $this->grind()->id, 'items' => [$this->latteId() => 1]])->assertSessionHasNoErrors();

        $redemption = Redemption::latest('id')->firstOrFail();

        $this->withSession(['redemption_id' => $redemption->id])->get('/employee')->assertOk()
            ->assertSeeInOrder(['Subtotal', '$5.75', 'DC meals tax 10%', '$0.58', 'Total', '$6.33'])
            ->assertSee('data-tax-breakdown', false);
    }

    public function test_typed_in_and_older_redemptions_say_how_tax_was_handled(): void
    {
        $this->signIn(Role::Employee);
        $this->post('/employee/redeem', ['merchant_id' => $this->grind()->id, 'amount' => '6.50'])->assertSessionHasNoErrors();
        $typed = Redemption::latest('id')->firstOrFail();

        $this->withSession(['redemption_id' => $typed->id])->get('/employee')->assertOk()
            ->assertSee('Amount includes any tax charged by the shop.');

        // A row from before tax display (no tax basis) still shows, unchanged.
        $typed->forceFill(['tax_basis' => null])->save();
        $this->get('/employee')->assertOk()->assertSee('Tax not itemized (before tax display)')->assertSee('$6.50');
    }

    public function test_a_later_rate_change_never_changes_a_receipt(): void
    {
        $this->signIn(Role::Employee);
        $this->post('/employee/redeem', ['merchant_id' => $this->grind()->id, 'items' => [$this->latteId() => 1]]);
        $redemption = Redemption::latest('id')->firstOrFail();

        config(['tax.categories.prepared_food.rates' => [['effective_from' => '2025-10-01', 'rate_bps' => 2500]]]);

        $this->withSession(['redemption_id' => $redemption->id])->get('/employee')->assertOk()
            ->assertSeeInOrder(['DC meals tax 10%', '$0.58', 'Total', '$6.33']);
    }

    public function test_the_pay_code_shows_subtotal_tax_and_total(): void
    {
        $this->signIn(Role::Employee);
        $this->post(route('employee.pay-code.store'), ['pay_merchant_id' => $this->grind()->id, 'items' => [$this->latteId() => 2]]);

        $this->get('/employee?tab=in-person')->assertOk()
            ->assertSeeInOrder(['$12.65', '2× Latte', 'Subtotal', '$11.50', 'DC meals tax 10%', '$1.15', 'Total', '$12.65']);
    }

    public function test_the_judges_sample_pay_code_adds_up(): void
    {
        $this->signIn(Role::Judge);

        $this->get('/employee?tab=in-person')->assertOk()
            ->assertSeeInOrder(['Sample code screen', '$12.65', '2× Latte', 'Subtotal', '$11.50', 'DC meals tax 10%', '$1.15', 'Total', '$12.65']);
    }
}
