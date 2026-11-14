<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Services\Ai\ClaudeClient;
use App\Services\BidImpactMetrics;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSandboxes;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * Seeing money move is the point of a sandbox (ADR 0045). Each role's own
 * figures are shown in it: the employer's credit balance and purchases, the
 * employee's balance and payments, the merchant's sales and payouts, and the
 * BID's impact totals. Only platform-level figures (fees, money in hand,
 * Local Goods Operating, sweeps, Stripe balances) are hidden, as for judges.
 */
class SandboxFiguresVisibleTest extends TestCase
{
    use RefreshDatabase, UsesSandboxes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSandboxes();
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(10, 'clear'));
        $this->startSandbox();
    }

    public function test_each_role_sees_its_own_money_figures_and_platform_figures_stay_hidden(): void
    {
        // Employer (HR): the credit balance and the purchase just made.
        $this->sandboxPost(route('employer.purchases.store'), ['purchase_amount' => '432.10'])->assertSessionHasNoErrors();
        $employerBalance = $this->insideSandbox(fn () => Employer::orderBy('id')->first()->balanceCents());
        $this->sandboxGet(route('employer.dashboard'))->assertOk()
            ->assertSee(Money::format($employerBalance))
            ->assertSee('$432.10');

        // Employee: the wallet balance and the payment just made.
        $this->switchRole('employee');
        $grind = $this->insideSandbox(fn () => Merchant::where('name', 'Farragut Grind')->value('id'));
        $this->sandboxPost(route('employee.redeem'), ['merchant_id' => $grind, 'amount' => '13.57'])->assertSessionHasNoErrors();
        $walletBalance = $this->insideSandbox(fn () => Employee::whereHas('user', fn ($q) => $q->where('email', 'employee@sandbox.invalid'))->firstOrFail()->balanceCents());
        $this->sandboxGet(route('employee.wallet'))->assertOk()
            ->assertSee('aria-label="Available balance: '.Money::format($walletBalance).'"', false)
            ->assertSee('$13.57');

        // Merchant: the sale, what is owed for it (simulated) and the payout history amounts.
        $this->assertTrue($this->insideSandbox(fn () => Redemption::orderByDesc('id')->firstOrFail()->isApproved()));
        [$owed, $lastPayout] = $this->insideSandbox(function () {
            $merchant = Merchant::where('name', 'Farragut Grind')->firstOrFail();

            return [$merchant->owedSimulatedCents(), $merchant->payouts()->latest('id')->firstOrFail()];
        });
        $this->assertGreaterThanOrEqual(1357, $owed);
        $this->switchRole('merchant');
        $merchantPage = $this->sandboxGet(route('merchant.dashboard'))->assertOk()
            ->assertSee('$13.57')
            ->assertSee('data-owed="simulated">'.Money::format($owed).'<', false)
            ->assertSee(Money::format($lastPayout->amount_cents));

        // BID admin: the impact totals.
        $this->switchRole('bid_admin');
        $totals = $this->insideSandbox(fn () => app(BidImpactMetrics::class)->totals());
        $this->assertGreaterThan(0, $totals['spent_cents']);
        $this->sandboxGet(route('bid-admin.impact'))->assertOk()
            ->assertSee(Money::format($totals['spent_cents']).' since launch');

        // Platform-level figures stay hidden: money in hand, Local Goods Operating, fees.
        $this->switchRole('reviewer');
        $this->sandboxGet(route('money'))->assertOk()
            ->assertSee('data-customer-funds', false)
            ->assertDontSee('data-in-hand', false)
            ->assertSee('data-operating-funds="hidden"', false)
            ->assertDontSee('data-operating-funds>', false)
            ->assertDontSee('data-operating-stripe-balance', false)
            ->assertDontSee('Paid in as platform fees');
        $merchantPage->assertDontSee('data-fee-lines', false);
    }
}
