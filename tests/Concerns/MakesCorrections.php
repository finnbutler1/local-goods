<?php

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\Correction;
use App\Models\CorrectionAction;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\CreditLedger;
use App\Services\Payments\StripeOnboarding;
use App\Services\RedemptionBasket;
use App\Services\RedemptionService;
use Tests\Fakes\FakeClaudeClient;

/**
 * Shared set-up for the corrections tests (ADR 0028): Stripe-paid
 * purchases, an onboarded merchant, redemptions (with or without menu
 * items), requests, the platform admin who decides, and the ledger
 * invariant with corrections in it. Use with UsesFakeStripe for Stripe.
 */
trait MakesCorrections
{
    protected function farragut(): Merchant
    {
        return Merchant::where('name', config('demo.accounts.merchant.business'))->firstOrFail();
    }

    /** A synthetic employee who is not Jordan, with plenty of credits. */
    protected function shopper(): Employee
    {
        return Employee::orderBy('id')->skip(1)->firstOrFail();
    }

    /** A purchase paid by card in Stripe test mode (as the webhook records it). */
    protected function stripePurchase(int $cents = 50000, string $charge = 'ch_test_A'): CreditPurchase
    {
        $ledger = app(CreditLedger::class);
        $purchase = $ledger->requestPurchase(Employer::firstOrFail(), $cents, 'MPG-STRIPE-'.$charge);
        $ledger->markPurchasePaidByStripe($purchase, 'pi_'.$charge, $charge, $purchase->totalCents(), 'usd');

        return $purchase->fresh();
    }

    protected function onboard(?Merchant $merchant = null): void
    {
        $merchant ??= $this->farragut();
        $this->stripe->accounts['acct_test_FG'] = ['id' => 'acct_test_FG'];
        app(StripeOnboarding::class)->apply($merchant, $this->stripe->completeOnboarding('acct_test_FG'));
    }

    /** A redemption the AI clears, of a typed-in amount (topping the employee up first if needed). */
    protected function cleared(int $cents, ?Employee $employee = null): Redemption
    {
        $employee = ($employee ?? $this->shopper())->fresh();
        if (($short = $cents - $employee->balanceCents()) > 0) {
            app(CreditLedger::class)->allocate(Employer::firstOrFail(), $employee, $short);
        }
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(10, 'clear'));

        return app(RedemptionService::class)->redeem($employee->fresh(), $this->farragut(), $cents);
    }

    /**
     * A cleared menu order at Farragut Grind, with DC sales tax added.
     *
     * @param  array<string, int>  $order  product name => quantity
     */
    protected function clearedOrder(array $order, ?Employee $employee = null): Redemption
    {
        $merchant = $this->farragut();
        $ids = $merchant->products()->whereIn('name', array_keys($order))->pluck('id', 'name');
        $basket = RedemptionBasket::build($merchant, collect($order)->mapWithKeys(fn ($q, $name) => [$ids[$name] => $q])->all(), 0);
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(10, 'clear'));

        return app(RedemptionService::class)->redeem(($employee ?? $this->shopper())->fresh(), $merchant, $basket['total_cents'], $basket['items'], tax: $basket['tax']);
    }

    protected function admin(): User
    {
        return User::where('role', Role::PlatformAdmin)->first() ?? User::factory()->role(Role::PlatformAdmin)->create(['name' => 'Pat Admin']);
    }

    /** A requested refund of this redemption, as its employee asked. */
    protected function refundRequest(Redemption $redemption): Correction
    {
        return Correction::create([
            'type' => Correction::TYPE_REDEMPTION_REFUND, 'status' => Correction::STATUS_REQUESTED,
            'open_key' => Correction::openKeyFor(Correction::TYPE_REDEMPTION_REFUND, $redemption->id),
            'requested_by_role' => Role::Employee->value, 'reason_category' => 'wrong_amount',
            'redemption_id' => $redemption->id, 'merchant_id' => $redemption->merchant_id, 'employee_id' => $redemption->employee_id,
        ]);
    }

    /** The transfer.reversed event Stripe sends for this payout's transfer, as it stands now in the fake. */
    protected function reversedEvent(MerchantPayout $payout): array
    {
        return $this->stripeEvent('transfer.reversed', $this->stripe->transfer($payout->stripe_transfer_id));
    }

    /**
     * Money in (paid purchases and their fees) minus money out to merchants
     * (paid payouts, less what refunds pulled back), minus money held
     * (redemptions under review, employer refunds held or done) is exactly
     * the sum of every ledger entry.
     */
    protected function assertLedgerInvariant(): void
    {
        $paid = CreditPurchase::whereIn('status', CreditPurchase::PAID_STATUSES);
        $moneyIn = (int) (clone $paid)->sum('amount_cents') + (int) (clone $paid)->sum('platform_fee_cents');
        $paidOut = (int) MerchantPayout::paid()->sum('amount_cents') - (int) CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_REFUND_REVERSAL)->sum('amount_cents');
        $held = (int) Redemption::where('status', Redemption::STATUS_UNDER_REVIEW)->sum('amount_cents');
        // A part that went through always counts; the rest only while the refund is still open (Close releases it).
        $employerRefunds = (int) CorrectionAction::whereHas('correction', fn ($q) => $q->where('type', Correction::TYPE_EMPLOYER_REFUND))
            ->where(fn ($q) => $q->where('status', CorrectionAction::STATUS_SUCCEEDED)
                ->orWhereHas('correction', fn ($c) => $c->where('status', '!=', Correction::STATUS_CLOSED)))->sum('amount_cents');

        $this->assertSame($moneyIn - $paidOut - $held - $employerRefunds, (int) CreditLedgerEntry::sum('amount_cents'), 'The ledger invariant holds with corrections');
    }
}
