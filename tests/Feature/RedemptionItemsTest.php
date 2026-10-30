<?php

namespace Tests\Feature;

use App\Enums\DenialReason;
use App\Enums\Role;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\MerchantProduct;
use App\Models\Redemption;
use App\Models\RedemptionItem;
use App\Services\Ai\ClaudeClient;
use App\Services\RedemptionReviewService;
use App\Services\RedemptionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * Redeeming from a menu (ADR 0023). The amount is the total of the items
 * plus DC sales tax on them (ADR 0027); the items are display only and Redemption Fraud Detection sees exactly
 * what it saw before menus.
 */
class RedemptionItemsTest extends TestCase
{
    use RefreshDatabase;

    private FakeClaudeClient $claude;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'America/New_York'));
        $this->seed(DatabaseSeeder::class);
        $this->signIn(Role::Employee);
        $this->claude = FakeClaudeClient::verdict(5, 'clear');
        $this->app->instance(ClaudeClient::class, $this->claude);
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

    private function product(string $name): MerchantProduct
    {
        return $this->grind()->products()->where('name', $name)->firstOrFail();
    }

    public function test_the_amount_is_the_sum_of_the_items_plus_tax(): void
    {
        $latte = $this->product('Latte');
        $croissant = $this->product('Croissant');
        $balance = Employee::firstOrFail()->balanceCents();

        $this->post('/employee/redeem', [
            'merchant_id' => $this->grind()->id,
            'items' => [$latte->id => 2, $croissant->id => 1, $this->product('Cold brew')->id => 0],
        ])->assertSessionHasNoErrors()->assertSessionHas('status', fn ($s) => str_contains($s, '(2× Latte, 1× Croissant) at Farragut Grind'));

        $redemption = Redemption::with('items')->latest('id')->firstOrFail();
        $this->assertSame(2 * $latte->price_cents + $croissant->price_cents, $redemption->subtotal_cents);
        $this->assertSame(158, $redemption->tax_cents); // 10% of $15.75, half up
        $this->assertSame($redemption->subtotal_cents + $redemption->tax_cents, $redemption->amount_cents);
        $this->assertSame($redemption->subtotal_cents, $redemption->items->sum->totalCents());
        $this->assertSame('2× Latte, 1× Croissant', $redemption->itemsSummary());
        $this->assertSame($balance - $redemption->amount_cents, Employee::firstOrFail()->balanceCents());
    }

    public function test_an_other_amount_is_its_own_line_and_part_of_the_total_with_no_tax_added(): void
    {
        $latte = $this->product('Latte');

        $this->post('/employee/redeem', ['merchant_id' => $this->grind()->id, 'items' => [$latte->id => 1], 'amount' => '3'])
            ->assertSessionHasNoErrors();

        $redemption = Redemption::with('items')->latest('id')->firstOrFail();
        $this->assertSame($latte->price_cents + 300, $redemption->subtotal_cents);
        $this->assertSame(58, $redemption->tax_cents); // on the Latte only
        $this->assertSame($latte->price_cents + 300 + 58, $redemption->amount_cents);
        $this->assertNull($redemption->items->last()->tax_category);
        $this->assertSame('1× Latte, Other amount $3.00', $redemption->itemsSummary());
    }

    public function test_an_amount_alone_is_a_bare_amount_as_before(): void
    {
        $this->post('/employee/redeem', ['merchant_id' => $this->grind()->id, 'amount' => '6.50'])->assertSessionHasNoErrors();

        $redemption = Redemption::latest('id')->firstOrFail();
        $this->assertSame(650, $redemption->amount_cents);
        $this->assertSame(0, RedemptionItem::whereHas('redemption', fn ($q) => $q->where('scored_by', '!=', 'seed'))->count());
        $this->assertNull($redemption->itemsSummary());
    }

    public function test_the_fraud_ai_sees_exactly_the_same_with_or_without_items(): void
    {
        $latte = $this->product('Latte');
        $croissant = $this->product('Croissant');
        $total = 2 * $latte->price_cents + $croissant->price_cents;
        $employee = Employee::firstOrFail();
        $service = app(RedemptionService::class);

        $state = $this->snapshotState();
        $withItems = $service->redeem($employee, $this->grind(), $total, [
            ['merchant_product_id' => $latte->id, 'name' => $latte->name, 'unit_price_cents' => $latte->price_cents, 'quantity' => 2],
            ['merchant_product_id' => $croissant->id, 'name' => $croissant->name, 'unit_price_cents' => $croissant->price_cents, 'quantity' => 1],
        ]);

        // Put everything back so the second redemption is scored from the same state.
        $this->restoreState($state, $withItems);
        $bare = $service->redeem($employee->fresh(), $this->grind(), $total);

        $this->assertCount(2, $this->claude->calls);
        $this->assertSame($this->claude->calls[0]['system'], $this->claude->calls[1]['system']);
        $this->assertSame($this->claude->calls[0]['user'], $this->claude->calls[1]['user']);
        $this->assertSameIgnoringKeyOrder($withItems->signals, $bare->signals);
        foreach (['Latte', 'Croissant', 'items', 'product'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $this->claude->calls[0]['user']);
        }
    }

    public function test_products_from_another_merchant_or_hidden_ones_are_refused(): void
    {
        $deliProduct = Merchant::where('name', 'Press Room Deli')->firstOrFail()->products()->firstOrFail();
        $hidden = tap($this->product('Chai latte'))->update(['is_available' => false]);

        foreach ([$deliProduct->id, $hidden->id, 999999, 'abc'] as $id) {
            $this->post('/employee/redeem', ['merchant_id' => $this->grind()->id, 'items' => [$id => 1]])->assertSessionHasErrors('items');
        }

        $this->assertSame(0, RedemptionItem::whereHas('redemption', fn ($q) => $q->where('scored_by', '!=', 'seed'))->count());
        $this->assertCount(0, $this->claude->calls);
    }

    public function test_quantities_must_be_whole_numbers_from_0_to_20(): void
    {
        $latte = $this->product('Latte');

        foreach (['-1', '21', '1.5', 'two'] as $quantity) {
            $this->post('/employee/redeem', ['merchant_id' => $this->grind()->id, 'items' => [$latte->id => $quantity]])
                ->assertSessionHasErrors("items.{$latte->id}");
        }

        $this->post('/employee/redeem', ['merchant_id' => $this->grind()->id, 'items' => [$latte->id => 0]])->assertSessionHasErrors('amount');
        $this->assertCount(0, $this->claude->calls);
    }

    public function test_a_total_over_the_balance_is_refused(): void
    {
        $pack = Merchant::where('name', 'Lafayette Cycle Studio')->firstOrFail()->products()->where('name', '5-class pack')->firstOrFail();

        $this->post('/employee/redeem', ['merchant_id' => $pack->merchant_id, 'items' => [$pack->id => 1]])
            ->assertSessionHasErrors('amount');
        $this->assertSame(0, RedemptionItem::whereHas('redemption', fn ($q) => $q->where('scored_by', '!=', 'seed'))->count());
    }

    public function test_items_that_dont_add_up_are_refused(): void
    {
        $this->expectException(LogicException::class);
        app(RedemptionService::class)->redeem(Employee::firstOrFail(), $this->grind(), 1000, [
            ['merchant_product_id' => null, 'name' => 'Other amount', 'unit_price_cents' => 900, 'quantity' => 1],
        ]);
    }

    public function test_menu_shows_after_choosing_a_merchant(): void
    {
        $this->get('/employee')->assertOk()->assertSee('Show menu')->assertDontSee('One more Latte');

        $this->get('/employee?merchant='.$this->grind()->id)->assertOk()
            ->assertSee('At Farragut Grind')
            ->assertSee('One more Latte')
            ->assertSee('$5.75')
            ->assertSee('Other amount (optional)')
            ->assertSee('data-basket', false);
    }

    public function test_the_suspicious_demo_still_fills_the_whole_balance_as_an_other_amount(): void
    {
        $this->get('/employee?suspicious=1')->assertOk()
            ->assertSee('Other amount (optional)')
            ->assertSee('value="120"', false);
    }

    public function test_items_show_in_the_wallet(): void
    {
        $this->post('/employee/redeem', ['merchant_id' => $this->grind()->id, 'items' => [$this->product('Latte')->id => 2]]);

        $this->get('/employee')->assertOk()->assertSeeInOrder(['Approved', '2× Latte', 'Recent redemptions', '2× Latte']);
    }

    public function test_items_show_on_the_merchant_dashboard_fraud_review_and_employer_view(): void
    {
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(85, 'hold', 'Unusual.'));
        $this->post('/employee/redeem', ['merchant_id' => $this->grind()->id, 'items' => [$this->product('Latte')->id => 2, $this->product('Croissant')->id => 1]]);
        $held = Redemption::latest('id')->firstOrFail();
        $this->assertTrue($held->isUnderReview());
        $initials = $held->employee->initials();

        $this->actingAs($this->signIn(Role::Merchant))->get('/merchant')->assertOk()
            ->assertSeeInOrder([$initials, '2× Latte, 1× Croissant'])
            ->assertDontSee('Jordan Rivera');

        $admin = $this->signIn(Role::BidAdmin);
        $this->actingAs($admin)->get('/bid-admin')->assertOk()
            ->assertSee('2× Latte, 1× Croissant')
            ->assertSee('not sent to Redemption Fraud Detection');

        app(RedemptionReviewService::class)->deny($held, $admin, 'Test denial.', DenialReason::SuspectedAbuse);
        $this->actingAs($this->signIn(Role::EmployerAdmin))->get('/employer')->assertOk()
            ->assertSee("Denied redemption #{$held->id} (2× Latte, 1× Croissant)")
            ->assertSee($initials)
            ->assertDontSee('Jordan Rivera');
    }

    /** @return array{redemptions: int, ledger: int} */
    private function snapshotState(): array
    {
        return ['redemptions' => Redemption::max('id'), 'ledger' => DB::table('credit_ledger_entries')->max('id')];
    }

    /** @param  array{redemptions: int, ledger: int}  $state */
    private function restoreState(array $state, Redemption $made): void
    {
        foreach (['merchant_payouts', 'redemption_items'] as $table) {
            DB::table($table)->where('redemption_id', $made->id)->delete();
        }
        DB::table('credit_ledger_entries')->where('id', '>', $state['ledger'])->delete();
        DB::table('redemptions')->where('id', '>', $state['redemptions'])->delete();
    }
}
