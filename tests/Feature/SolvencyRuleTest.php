<?php

namespace Tests\Feature;

use App\Models\Correction;
use App\Models\CorrectionAction;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPurchase;
use App\Models\MerchantPayout;
use App\Models\Redemption;
use App\Services\Solvency\SolvencyCheck;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsDemoActivity;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * The solvency rule (ADR 0033) holds over the whole seeded history and a
 * day of generated activity, at every moment, exactly: money in hand
 * equals everything promised, the Stripe floor holds and the ledger
 * agrees with the purchase, payout and refund records.
 */
class SolvencyRuleTest extends TestCase
{
    use RefreshDatabase;
    use RunsDemoActivity;
    use SeedsOnAFixedDate;
    use UsesFakeStripe;

    public function test_the_seeded_demo_is_solvent_at_every_moment(): void
    {
        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);

        $history = app(SolvencyCheck::class)->history();

        $this->assertTrue($history->passed(), $history->summary());
        $this->assertSame(CreditLedgerEntry::count(), $history->entries);
        $this->assertGreaterThan(100, $history->moments);
        $this->assertSame(app(SolvencyCheck::class)->now()->toArray(), $history->final->toArray(), 'The replay ends where the current check is.');
        $this->assertNetInvariantMatches();
    }

    public function test_a_generated_day_in_stripe_test_mode_keeps_it_solvent_at_every_moment(): void
    {
        $this->startDemoActivity();
        $this->runDay('2026-10-26');

        $check = app(SolvencyCheck::class);
        $history = $check->history();
        $now = $check->now();

        $this->assertTrue($history->passed(), $history->summary());
        $this->assertTrue($now->passes(), (string) $now->problem());
        $this->assertGreaterThan(0, $now->stripeInCents, 'The day bought credits through Stripe.');
        $this->assertGreaterThan(0, $now->stripeOutCents, 'And sent Stripe transfers.');
        $this->assertSame([], $check->recordsAgree());
        $this->assertSame($now->toArray(), $history->final->toArray());
        $this->assertNetInvariantMatches();
    }

    /** The rule with net balances is the ledger invariant the corrections tests already check. */
    private function assertNetInvariantMatches(): void
    {
        $paid = CreditPurchase::whereIn('status', CreditPurchase::PAID_STATUSES);
        $moneyIn = (int) (clone $paid)->sum('amount_cents') + (int) (clone $paid)->sum('platform_fee_cents');
        $paidOut = (int) MerchantPayout::paid()->sum('amount_cents') - (int) CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_REFUND_REVERSAL)->sum('amount_cents');
        $refunds = (int) CorrectionAction::whereHas('correction', fn ($q) => $q->where('type', Correction::TYPE_EMPLOYER_REFUND))
            ->where('status', CorrectionAction::STATUS_SUCCEEDED)->sum('amount_cents');

        $now = app(SolvencyCheck::class)->now();
        $this->assertSame($moneyIn - $paidOut - $refunds, $now->inHandCents());
        $this->assertSame((int) Redemption::where('status', Redemption::STATUS_UNDER_REVIEW)->sum('amount_cents'), $now->heldRedemptionsCents);
        $this->assertSame($now->inHandCents() - $now->heldPendingCents(), (int) CreditLedgerEntry::sum('amount_cents'));
    }
}
