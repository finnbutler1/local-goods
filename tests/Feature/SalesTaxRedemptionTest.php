<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CreditLedgerEntry;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\MerchantProduct;
use App\Models\PayCode;
use App\Models\Redemption;
use App\Services\Ai\ClaudeClient;
use App\Services\CreditLedger;
use App\Services\PayCodes;
use App\Services\Payments\StripeOnboarding;
use App\Services\RedemptionBasket;
use App\Services\RedemptionService;
use App\Support\SalesTax;
use App\Support\TaxBreakdown;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\Concerns\UsesFakeStripe;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * DC sales tax at redemption (ADR 0027): the total is the item subtotal plus
 * tax, and that one total is what the AI sees, the ledger debits, the
 * merchant earns and Stripe transfers. Typed-in amounts get no tax added.
 */
class SalesTaxRedemptionTest extends TestCase
{
    use RefreshDatabase;
    use UsesFakeStripe;

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

    private function merchant(string $name = 'Farragut Grind'): Merchant
    {
        return Merchant::where('name', $name)->firstOrFail();
    }

    private function product(string $name, string $merchant = 'Farragut Grind'): MerchantProduct
    {
        return $this->merchant($merchant)->products()->where('name', $name)->firstOrFail();
    }

    private function orderLatte(array $extra = []): Redemption
    {
        $this->signIn(Role::Employee);
        $this->post('/employee/redeem', ['merchant_id' => $this->merchant()->id, 'items' => [$this->product('Latte')->id => 1]] + $extra)
            ->assertSessionHasNoErrors();

        return Redemption::with('items')->latest('id')->firstOrFail();
    }

    public function test_a_latte_is_575_plus_58_meals_tax_is_633(): void
    {
        $redemption = $this->orderLatte();

        $this->assertSame([TaxBreakdown::ADDED, 575, 58, 633], [$redemption->tax_basis, $redemption->subtotal_cents, $redemption->tax_cents, $redemption->amount_cents]);
        $this->assertSame([['category' => 'prepared_food', 'label' => 'DC meals tax', 'rate_bps' => 1000, 'taxable_cents' => 575, 'tax_cents' => 58]], $redemption->tax_lines);
        $this->assertSame(['prepared_food', 1000], [$redemption->items[0]->tax_category, $redemption->items[0]->tax_rate_bps]);
    }

    public function test_the_ledger_debits_and_the_merchant_earns_the_total(): void
    {
        $employee = Employee::firstOrFail();
        $before = $employee->balanceCents();
        $payableBefore = $this->merchant()->payableCents();

        $redemption = $this->orderLatte();

        $this->assertSame(-633, CreditLedgerEntry::where('redemption_id', $redemption->id)->where('type', CreditLedgerEntry::TYPE_REDEMPTION)->value('amount_cents'));
        $this->assertSame(633, CreditLedgerEntry::where('redemption_id', $redemption->id)->where('type', CreditLedgerEntry::TYPE_MERCHANT_EARNED)->value('amount_cents'));
        $this->assertSame($before - 633, $employee->balanceCents());
        $this->assertSame($payableBefore + 633, $this->merchant()->payableCents());
    }

    public function test_the_stripe_transfer_is_the_total_and_reconciliation_still_matches(): void
    {
        $this->fakeStripe();
        $ledger = app(CreditLedger::class);
        $purchase = $ledger->requestPurchase(Employer::firstOrFail(), 50000, 'MPG-STRIPE-TAX');
        $purchase->update(['payment_token' => 'tok-tax']);
        $ledger->markPurchasePaidByStripe($purchase, 'pi_test_tax', 'ch_test_tax', 50000, 'usd');
        $this->stripe->accounts['acct_test_FG'] = ['id' => 'acct_test_FG'];
        app(StripeOnboarding::class)->apply($this->merchant(), $this->stripe->completeOnboarding('acct_test_FG'));

        $redemption = $this->orderLatte();

        $this->assertCount(1, $this->stripe->transfers);
        $this->assertSame(633, $this->stripe->transfers[0]['amount']);
        $this->assertSame((string) $redemption->id, (string) $this->stripe->transfers[0]['metadata']['lg_redemption_id']);

        $this->stripe->paymentIntents = [[
            'id' => 'pi_test_tax', 'status' => 'succeeded', 'amount' => 50000, 'amount_received' => 50000, 'currency' => 'usd',
            'metadata' => ['lg_purchase_token' => 'tok-tax'],
        ]];
        $this->stripe->balance = ['available' => [['amount' => 50000 - 633, 'currency' => 'usd']], 'pending' => [['amount' => 0, 'currency' => 'usd']]];

        $this->signIn(Role::PlatformAdmin);
        $this->get('/admin/reconciliation')->assertOk()->assertSee('data-reconciled="yes"', false)->assertSee('$6.33');
    }

    public function test_a_typed_in_amount_gets_no_tax_added_to_go(): void
    {
        $this->signIn(Role::Employee);
        $this->post('/employee/redeem', ['merchant_id' => $this->merchant()->id, 'amount' => '6.50'])->assertSessionHasNoErrors();

        $redemption = Redemption::latest('id')->firstOrFail();
        $this->assertSame([TaxBreakdown::INCLUDED, 650, null, null, null], [$redemption->tax_basis, $redemption->amount_cents, $redemption->subtotal_cents, $redemption->tax_cents, $redemption->tax_lines]);
    }

    public function test_a_typed_in_amount_gets_no_tax_added_in_person(): void
    {
        $this->signIn(Role::Employee);
        $this->post(route('employee.pay-code.store'), ['pay_merchant_id' => $this->merchant()->id, 'amount' => '6.50'])->assertSessionHasNoErrors();

        $code = PayCode::latest('id')->firstOrFail();
        $this->assertSame([TaxBreakdown::INCLUDED, 650, null], [$code->tax_basis, $code->amount_cents, $code->tax_cents]);
    }

    public function test_a_pay_code_amount_includes_tax_and_confirming_keeps_the_snapshot(): void
    {
        $this->signIn(Role::Employee);
        $this->post(route('employee.pay-code.store'), ['pay_merchant_id' => $this->merchant()->id, 'items' => [$this->product('Latte')->id => 2]])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Pay code ready for $12.65'));

        $code = PayCode::latest('id')->firstOrFail();
        $this->assertSame([TaxBreakdown::ADDED, 1150, 115, 1265], [$code->tax_basis, $code->subtotal_cents, $code->tax_cents, $code->amount_cents]);

        $redemption = app(PayCodes::class)->confirm($code, $this->merchant());

        $this->assertSame([TaxBreakdown::ADDED, 1150, 115, 1265], [$redemption->tax_basis, $redemption->subtotal_cents, $redemption->tax_cents, $redemption->amount_cents]);
        $this->assertSame($code->tax_lines, $redemption->fresh()->tax_lines);
        $this->assertSame(['prepared_food'], $redemption->items()->pluck('tax_category')->all());
    }

    public function test_a_basket_across_categories(): void
    {
        $cycle = $this->merchant('Lafayette Cycle Studio');
        $this->product('Recovery smoothie', 'Lafayette Cycle Studio')->update(['tax_category' => 'prepared_food']);
        $this->product('Bottled water', 'Lafayette Cycle Studio')->update(['tax_category' => 'exempt']);
        $basket = RedemptionBasket::build($cycle, [
            $this->product('Drop-in class', 'Lafayette Cycle Studio')->id => 1,     // general, 3200 → 192
            $this->product('Recovery smoothie', 'Lafayette Cycle Studio')->id => 1, // prepared food, 900 → 90
            $this->product('Bottled water', 'Lafayette Cycle Studio')->id => 1,     // exempt, 300 → 0
        ], 0);

        $this->assertSame(4400, $basket['tax']->subtotalCents);
        $this->assertSame(282, $basket['tax']->taxCents);
        $this->assertSame(4682, $basket['total_cents']);
    }

    public function test_an_exempt_only_basket_costs_its_subtotal(): void
    {
        $salon = $this->merchant('K Street Cuts');
        $basket = RedemptionBasket::build($salon, [$salon->products()->where('name', 'Classic cut')->value('id') => 1], 0);

        $this->assertSame([4200, 0, 4200], [$basket['tax']->subtotalCents, $basket['tax']->taxCents, $basket['total_cents']]);
    }

    public function test_the_snapshot_never_changes_when_the_rates_do(): void
    {
        $redemption = $this->orderLatte();

        config(['tax.categories.prepared_food.rates' => [['effective_from' => '2025-10-01', 'rate_bps' => 2500]]]);
        $this->assertSame(2500, SalesTax::rateBps('prepared_food'));

        $fresh = $redemption->fresh();
        $this->assertSame([575, 58, 633, 1000], [$fresh->subtotal_cents, $fresh->tax_cents, $fresh->amount_cents, $fresh->tax_lines[0]['rate_bps']]);
        $this->assertSame(58, $fresh->taxBreakdown()->taxCents);
    }

    public function test_redeem_refuses_a_breakdown_that_does_not_add_up(): void
    {
        $tax = SalesTax::forItems([['name' => 'Latte', 'unit_price_cents' => 575, 'quantity' => 1, 'tax_category' => 'prepared_food']]);

        $this->expectException(LogicException::class);
        app(RedemptionService::class)->redeem(Employee::firstOrFail(), $this->merchant(), 575, $tax->items, Redemption::CHANNEL_TO_GO, $tax);
    }

    public function test_a_product_with_no_category_falls_back_to_its_merchants_default(): void
    {
        $latte = $this->product('Latte');
        $latte->forceFill(['tax_category' => null])->saveQuietly();

        $this->assertSame('prepared_food', $latte->fresh()->taxCategory());
    }
}
