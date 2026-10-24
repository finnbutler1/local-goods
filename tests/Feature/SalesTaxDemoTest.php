<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Controllers\JudgeController;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\MerchantProduct;
use App\Models\Redemption;
use App\Services\MerchantTaxSummary;
use App\Support\TaxBreakdown;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoHistorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The demo's sales tax data (ADR 0027): every menu product has a tax
 * category after seeding, Reset demo and the menu-only seed; seeded
 * typed-in history says tax is included; three seeded menu orders carry
 * DC tax, so the merchant's "Tax collected" isn't empty.
 */
class SalesTaxDemoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'America/New_York'));
        $this->seed(DatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function categories(): array
    {
        return MerchantProduct::with('merchant')->get()->mapWithKeys(fn ($p) => [$p->merchant->name.' / '.$p->name => $p->tax_category])->all();
    }

    public function test_every_seeded_product_has_a_category_with_the_documented_overrides(): void
    {
        $categories = $this->categories();

        $this->assertNotContains(null, $categories);
        $this->assertSame('prepared_food', $categories['Farragut Grind / Latte']);
        $this->assertSame('prepared_food', $categories['Press Room Deli / Fountain drink']);
        $this->assertSame('general', $categories['Page & Quill Books / Paperback']);
        $this->assertSame('general', $categories['Lafayette Cycle Studio / Drop-in class']);
        $this->assertSame('prepared_food', $categories['Lafayette Cycle Studio / Recovery smoothie']);
        $this->assertSame('exempt', $categories['Lafayette Cycle Studio / Bottled water']);
        $this->assertSame('exempt', $categories['K Street Cuts / Classic cut']);
    }

    public function test_reset_demo_and_the_menu_only_seed_restore_the_categories(): void
    {
        $seeded = $this->categories();

        MerchantProduct::query()->update(['tax_category' => 'general']);
        $this->signIn(Role::BidAdmin);
        $this->post('/bid-admin/reset')->assertRedirect();
        $this->assertEquals($seeded, $this->categories());

        DB::table('merchant_products')->delete();
        $this->artisan('demo:seed-if-empty')->expectsOutputToContain('product menus')->assertSuccessful();
        $this->assertEquals($seeded, $this->categories());
    }

    public function test_seeded_typed_in_history_says_tax_is_included_and_jordan_keeps_twelve(): void
    {
        $jordan = Employee::orderBy('id')->firstOrFail();

        $this->assertSame(12, $jordan->redemptions()->count());
        $this->assertSame(12, $jordan->redemptions()->where('tax_basis', TaxBreakdown::INCLUDED)->whereNull('tax_cents')->count());
        $this->assertSame(0, Redemption::whereNull('tax_basis')->count());
        $this->assertSame(TaxBreakdown::INCLUDED, DB::table('pay_codes')->value('tax_basis'));
    }

    public function test_three_seeded_menu_orders_carry_dc_tax_and_add_up(): void
    {
        $taxed = Redemption::with(['items', 'employee', 'merchant'])->where('tax_basis', TaxBreakdown::ADDED)->orderBy('created_at')->get();

        $this->assertCount(3, $taxed);
        foreach ($taxed as $redemption) {
            $this->assertSame(DemoHistorySeeder::TAXED_ORDER_MERCHANT, $redemption->merchant->name);
            $this->assertContains($redemption->employee->display_name, DemoHistorySeeder::TAXED_ORDER_EMPLOYEES);
            $this->assertSame($redemption->subtotal_cents, $redemption->items->sum->totalCents());
            $this->assertSame($redemption->subtotal_cents + $redemption->tax_cents, $redemption->amount_cents);
            $this->assertSame(-$redemption->amount_cents, (int) DB::table('credit_ledger_entries')->where('redemption_id', $redemption->id)->where('type', 'redemption')->value('amount_cents'));
            $this->assertSame(Redemption::STATUS_APPROVED, $redemption->status);
        }

        // 2× Latte + Croissant = $15.75 → $1.58; Cappuccino $5.50 → $0.55; Avocado toast + Cold brew $16.75 → $1.68.
        $this->assertSame([158, 55, 168], $taxed->pluck('tax_cents')->all());

        $summary = MerchantTaxSummary::for(Merchant::where('name', 'Farragut Grind')->firstOrFail());
        $this->assertSame(55 + 168, $summary['this_month']['tax_cents']);
        $this->assertSame(158, $summary['last_month']['tax_cents']);
    }

    public function test_no_balance_goes_below_zero(): void
    {
        foreach (Employee::all() as $employee) {
            $this->assertGreaterThanOrEqual(0, $employee->balanceCents(), $employee->initials());
        }
    }

    public function test_the_judge_tour_points_at_tax_at_checkout(): void
    {
        $step = collect(JudgeController::tour())->firstWhere('title', 'See tax at checkout');

        $this->assertNotNull($step);
        $this->assertStringStartsWith('Order from a menu in the wallet.', $step['look_for']);
        $this->assertStringContainsString('merchant='.Merchant::where('name', 'Farragut Grind')->value('id'), $step['url']);
    }
}
