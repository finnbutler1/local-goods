<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Controllers\Merchant\PayCodeController;
use App\Http\Controllers\Merchant\RedemptionsExportController;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\MerchantProduct;
use App\Models\PayCode;
use App\Models\Redemption;
use App\Services\Ai\ClaudeClient;
use App\Services\MerchantTaxSummary;
use App\Services\PayCodes;
use App\Services\RedemptionBasket;
use App\Services\RedemptionService;
use App\Support\TaxBreakdown;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * What a merchant sees of DC sales tax (ADR 0027): the breakdown on each
 * redemption and on the pay code review, "Tax collected" this month and
 * last, a CSV for their records, and a tax category on each menu product.
 */
class MerchantTaxTest extends TestCase
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

    private function latte(): MerchantProduct
    {
        return $this->grind()->products()->where('name', 'Latte')->firstOrFail();
    }

    /** A menu order of $count drip coffees (the first product) by the first employee, as the wallet makes it. */
    private function orderDripCoffee(int $count, ?Merchant $merchant = null): Redemption
    {
        $merchant ??= $this->grind();
        $basket = RedemptionBasket::build($merchant, [$merchant->products()->firstOrFail()->id => $count], 0);

        return app(RedemptionService::class)->redeem(Employee::firstOrFail(), $merchant, $basket['total_cents'], $basket['items'], tax: $basket['tax']);
    }

    private function taxBefore(string $key): int
    {
        return MerchantTaxSummary::for($this->grind())[$key]['tax_cents'];
    }

    public function test_tax_collected_counts_cleared_menu_orders_this_month_and_last(): void
    {
        $thisMonth = $this->taxBefore('this_month');
        $lastMonth = $this->taxBefore('last_month');

        $this->orderDripCoffee(1);                                   // Drip coffee $3.75 → $0.38
        Carbon::setTestNow(Carbon::parse('2026-09-30 23:30', 'America/New_York'));
        $this->orderDripCoffee(2);                                   // $7.50 → $0.75, last month in DC time
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'America/New_York'));

        $summary = MerchantTaxSummary::for($this->grind());
        $this->assertSame($thisMonth + 38, $summary['this_month']['tax_cents']);
        $this->assertSame($lastMonth + 75, $summary['last_month']['tax_cents']);
        $this->assertSame('October 2026', $summary['this_month']['label']);
        $this->assertSame('September 2026', $summary['last_month']['label']);
    }

    public function test_held_denied_typed_in_and_other_merchants_orders_are_not_counted(): void
    {
        $before = $this->taxBefore('this_month');

        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(90, 'hold'));
        $this->orderDripCoffee(1);
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(5, 'clear'));
        app(RedemptionService::class)->redeem(Employee::firstOrFail(), $this->grind(), 650);
        $this->orderDripCoffee(1, Merchant::where('name', 'Press Room Deli')->firstOrFail());

        $this->assertSame($before, $this->taxBefore('this_month'));
    }

    public function test_the_dashboard_shows_the_summary_with_its_label_and_the_csv_link(): void
    {
        $this->orderDripCoffee(1);
        $this->signIn(Role::Merchant);

        $this->get('/merchant')->assertOk()
            ->assertSee('Tax collected')
            ->assertSee('For your records. Your business files and remits DC sales tax; Local Goods does not.')
            ->assertSee('This month (October 2026)')
            ->assertSee('Last month (September 2026)')
            ->assertSee('Typed-in amounts already include any tax you charged')
            ->assertSee('Cleared sales this month (including tax)')
            ->assertSee(route('merchant.redemptions.csv'), false)
            ->assertSeeInOrder(['Redemptions received', 'Subtotal', '$3.75', 'DC meals tax 10%', '$0.38']);
    }

    public function test_older_redemptions_say_tax_was_not_itemized(): void
    {
        Redemption::where('merchant_id', $this->grind()->id)->update(['tax_basis' => null]);
        $this->signIn(Role::Merchant);

        $this->get('/merchant')->assertOk()->assertSee('Tax not itemized (before tax display)');
    }

    public function test_the_pay_code_review_shows_subtotal_tax_and_total(): void
    {
        $code = app(PayCodes::class)->issue(Employee::firstOrFail(), $this->grind(), ...$this->basketArgs(2));
        $this->signIn(Role::Merchant);

        $this->withSession([PayCodeController::SESSION_KEY => $code->id])->get('/merchant')->assertOk()
            ->assertSee('data-pay-review', false)
            ->assertSeeInOrder(['Amount', '$8.25', 'Subtotal', '$7.50', 'DC meals tax 10%', '$0.75', 'Total', '$8.25', 'Confirm payment']);
        $this->assertSame(825, PayCode::findOrFail($code->id)->amount_cents);
    }

    public function test_the_judges_sample_review_adds_up_with_tax(): void
    {
        $this->signIn(Role::Judge);

        $this->get('/merchant')->assertOk()
            ->assertSeeInOrder(['Sample, after a code is checked', '$12.65', '2× Latte', 'Subtotal', '$11.50', 'DC meals tax 10%', '$1.15', 'Total', '$12.65']);
    }

    public function test_the_csv_has_subtotal_tax_and_total_with_initials_only(): void
    {
        $order = $this->orderDripCoffee(1);
        $typed = app(RedemptionService::class)->redeem(Employee::firstOrFail(), $this->grind(), 650);
        $this->signIn(Role::Merchant);

        $response = $this->get(route('merchant.redemptions.csv'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment; filename=local-goods-redemptions-2026-10-20.csv', $response->headers->get('content-disposition'));
        $rows = array_map(str_getcsv(...), array_filter(explode("\n", $response->streamedContent())));

        $this->assertSame(RedemptionsExportController::HEADERS, $rows[0]);
        $byRef = collect(array_slice($rows, 1))->keyBy(1);
        $initials = Employee::firstOrFail()->initials();
        $this->assertSame(['2026-10-20 12:00', $order->reference(), 'To go', 'Cleared', $initials, '1× Drip coffee', '3.75', '0.38', '4.13', 'DC meals tax 10% $0.38'], $byRef[$order->reference()]);
        $this->assertSame(['', '', '6.50', 'Amount includes any tax charged by the shop'], array_slice($byRef[$typed->reference()], 6));
        // Completed refunds (ADR 0028; the seed has one) are rows of their own.
        $orders = array_filter(array_slice($rows, 1), fn ($row) => $row[3] !== 'Refund');
        $this->assertSame($this->grind()->redemptions()->count(), count($orders), 'only this merchant\'s redemptions');
        $this->assertStringNotContainsString(Employee::firstOrFail()->display_name, $response->streamedContent());
    }

    public function test_csv_cells_cannot_run_as_spreadsheet_formulas(): void
    {
        $this->assertSame("'=HYPERLINK(\"x\")", RedemptionsExportController::cell('=HYPERLINK("x")'));
        $this->assertSame("'+1", RedemptionsExportController::cell('+1'));
        $this->assertSame("'@SUM(A1)", RedemptionsExportController::cell('@SUM(A1)'));
        $this->assertSame('1× Latte', RedemptionsExportController::cell('1× Latte'));
    }

    public function test_a_judge_can_download_the_demo_merchants_csv_and_nothing_is_written(): void
    {
        $counts = fn () => [DB::table('redemptions')->count(), DB::table('credit_ledger_entries')->count()];
        $before = $counts();
        $this->signIn(Role::Judge);

        $this->get(route('merchant.redemptions.csv'))->assertOk();
        $this->assertSame($before, $counts());
    }

    public function test_the_menu_editor_has_a_tax_category_with_the_accountant_hint(): void
    {
        $this->signIn(Role::Merchant);

        $this->get(route('merchant.menu'))->assertOk()
            ->assertSee('Tax category')
            ->assertSee('Sets the DC tax added at checkout. Confirm this with your accountant.')
            ->assertSee('Prepared food and drinks (DC meals tax 10%)')
            ->assertSee('General merchandise and taxable services (DC sales tax 6%)')
            ->assertSee('Not taxed (for example groceries for home use and most personal services)')
            ->assertSee('<option value="prepared_food" selected>', false);
    }

    public function test_a_merchant_can_change_a_products_tax_category(): void
    {
        $latte = $this->latte();
        $this->signIn(Role::Merchant);

        $this->post(route('merchant.menu.update', $latte), ['name' => 'Latte', 'price' => '5.75', 'is_available' => '1', 'tax_category' => 'general'])
            ->assertSessionHasNoErrors();
        $this->assertSame('general', $latte->fresh()->tax_category);

        $this->post(route('merchant.menu.update', $latte), ['name' => 'Latte', 'price' => '5.75', 'is_available' => '1', 'tax_category' => 'luxury'])
            ->assertSessionHasErrorsIn('product'.$latte->id, ['tax_category' => 'Choose one of the tax categories listed.']);
        $this->assertSame('general', $latte->fresh()->tax_category);

        // Left out, the category is kept.
        $this->post(route('merchant.menu.update', $latte), ['name' => 'Latte', 'price' => '5.75', 'is_available' => '1']);
        $this->assertSame('general', $latte->fresh()->tax_category);
    }

    public function test_a_new_product_starts_with_the_merchants_default(): void
    {
        $this->signIn(Role::Merchant);

        $this->post(route('merchant.menu.store'), ['name' => 'Scone', 'price' => '3.50'])->assertSessionHasNoErrors();
        $this->assertSame('prepared_food', $this->grind()->products()->where('name', 'Scone')->value('tax_category'));

        $this->post(route('merchant.menu.store'), ['name' => 'Coffee beans (12 oz)', 'price' => '16', 'tax_category' => 'exempt'])->assertSessionHasNoErrors();
        $this->assertSame('exempt', $this->grind()->products()->where('name', 'Coffee beans (12 oz)')->value('tax_category'));
    }

    public function test_a_judge_cannot_change_a_tax_category(): void
    {
        $latte = $this->latte();
        $this->signIn(Role::Judge);

        $this->post(route('merchant.menu.update', $latte), ['name' => 'Latte', 'price' => '5.75', 'is_available' => '1', 'tax_category' => 'exempt'])
            ->assertForbidden();
        $this->assertSame('prepared_food', $latte->fresh()->taxCategory());
    }

    /** @return array{0: int, 1: array, 2: TaxBreakdown} */
    private function basketArgs(int $count): array
    {
        $basket = RedemptionBasket::build($this->grind(), [$this->grind()->products()->firstOrFail()->id => $count], 0);

        return [$basket['total_cents'], $basket['items'], $basket['tax']];
    }
}
