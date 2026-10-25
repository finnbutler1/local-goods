<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Redemption;
use App\Services\Corrections\CorrectionDecisions;
use App\Services\EmployerProgram;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesCorrections;
use Tests\TestCase;

/**
 * The merchant's "Cleared sales this month" is net of completed refunds
 * (ADR 0028 timing): a refund counts in the month it completed, in DC time,
 * whatever month the sale was. With a refund it shows Sales, Refunds and
 * Net; without one, just the figure as before.
 */
class MerchantClearedNetOfRefundsTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'America/New_York'));
        $this->seed(DatabaseSeeder::class);
        $this->withoutSeededCorrections();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function salesThisMonth(): int
    {
        return (int) $this->farragut()->redemptions()->where('status', Redemption::STATUS_APPROVED)->where('created_at', '>=', EmployerProgram::month()[0])->sum('amount_cents');
    }

    private function refund(Redemption $redemption, int $cents): void
    {
        app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Refund.', amountCents: $cents);
    }

    public function test_no_refund_shows_the_single_figure(): void
    {
        $this->signIn(Role::Merchant);

        $this->get('/merchant')->assertOk()
            ->assertSee('Cleared sales this month (including tax)')
            ->assertDontSee('net of refunds')
            ->assertDontSee('data-month-refunds', false)
            ->assertSee(Money::format($this->salesThisMonth()));
    }

    public function test_a_refund_this_month_shows_sales_refunds_and_net(): void
    {
        $this->refund($this->cleared(1250), 450);
        $sales = $this->salesThisMonth();
        $this->signIn(Role::Merchant);

        $this->get('/merchant')->assertOk()
            ->assertSee('Cleared sales this month (including tax), net of refunds')
            ->assertSee('Sales '.Money::format($sales).' · Refunds −$4.50 · Net '.Money::format($sales - 450))
            ->assertSee('data-month-net>'.Money::format($sales - 450), false);
    }

    public function test_a_refund_completed_this_month_on_last_months_sale_counts_this_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-28 12:00', 'America/New_York'));
        $september = $this->cleared(1000);
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'America/New_York'));
        $this->refund($september, 300);
        $sales = $this->salesThisMonth();
        $this->signIn(Role::Merchant);

        $this->get('/merchant')->assertOk()->assertSee('Sales '.Money::format($sales).' · Refunds −$3.00 · Net '.Money::format($sales - 300));

        // Next month the refund belongs to October, not November.
        Carbon::setTestNow(Carbon::parse('2026-11-02 12:00', 'America/New_York'));
        $this->get('/merchant')->assertOk()->assertDontSee('data-month-refunds', false);
    }
}
