<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Correction;
use App\Models\CorrectionAction;
use App\Models\CreditLedgerEntry;
use App\Models\Employer;
use App\Models\MerchantPayout;
use App\Services\Corrections\CorrectionDecisions;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * Stripe actions are retried safely (ADR 0028): a definite refusal shows the
 * reason in plain English and the next try uses a new idempotency key (Stripe
 * made nothing); a lost answer keeps the same key, so Stripe acts at most
 * once; an action Stripe already took is never sent again.
 */
class CorrectionRetryTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;
    use UsesFakeStripe;

    private MerchantPayout $payout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeStripe();
        $this->stripePurchase();
        $this->onboard();
        $this->payout = MerchantPayout::where('redemption_id', $this->cleared(1250)->id)->sole();
    }

    private function decisions(): CorrectionDecisions
    {
        return app(CorrectionDecisions::class);
    }

    private function approve(int $cents = 600): Correction
    {
        return $this->decisions()->approveRedemptionRefund($this->refundRequest($this->payout->redemption), $this->admin(), 'Refund part.', amountCents: $cents);
    }

    public function test_a_refusal_shows_why_and_a_retry_uses_a_new_key(): void
    {
        $this->stripe->reversalFailures[] = ['Stripe says the platform balance is too low for this transfer yet.', true];

        $correction = $this->approve();

        $this->assertSame(Correction::STATUS_FAILED, $correction->status);
        $this->assertSame('Stripe says the platform balance is too low for this transfer yet.', $correction->failure_reason);
        $action = $correction->actions->sole();
        $this->assertSame([CorrectionAction::STATUS_FAILED, 1], [$action->status, $action->attempts]);
        $firstKey = $action->idempotency_key;

        $retried = $this->decisions()->retry($correction, $this->admin());

        $this->assertSame(Correction::STATUS_PROCESSING, $retried->status);
        $this->assertNull($retried->failure_reason);
        $this->assertNotSame($firstKey, $retried->actions->sole()->idempotency_key);
        $this->assertStringEndsWith('-1', $retried->actions->sole()->idempotency_key);
        $this->assertCount(1, $this->stripe->transfer($this->payout->stripe_transfer_id)['reversals']['data']);

        $this->postWebhook($this->reversedEvent($this->payout))->assertOk();
        $this->assertSame(Correction::STATUS_COMPLETED, $correction->fresh()->status);
        $this->assertLedgerInvariant();
    }

    public function test_a_lost_answer_keeps_the_same_key_so_stripe_acts_once(): void
    {
        $this->stripe->reversalFailures[] = ['Could not reach Stripe. Nothing was lost; it will be tried again.', false];

        $correction = $this->approve();

        $this->assertSame(Correction::STATUS_PROCESSING, $correction->status, 'Unknown outcome: not failed');
        $action = $correction->actions->sole();
        $this->assertSame([CorrectionAction::STATUS_PROCESSING, 0, null], [$action->status, $action->attempts, $action->stripe_id]);
        $this->assertSame('Could not reach Stripe. Nothing was lost; it will be tried again.', $correction->failure_reason);

        $this->decisions()->retry($correction, $this->admin());
        $this->decisions()->retry($correction, $this->admin());

        $this->assertSame([$action->idempotency_key], array_values(array_unique(array_filter($this->stripe->idempotencyKeys, fn ($k) => str_starts_with($k, 'lg-correction-')))));
        $this->assertCount(1, $this->stripe->transfer($this->payout->stripe_transfer_id)['reversals']['data']);
        $this->assertNotNull($action->fresh()->stripe_id);
    }

    public function test_an_action_stripe_took_is_never_sent_again(): void
    {
        $correction = $this->approve();
        $this->decisions()->retry($correction, $this->admin());
        $this->decisions()->retry($correction, $this->admin());

        $this->assertCount(1, $this->stripe->callsOf('reversal'));

        $this->postWebhook($this->reversedEvent($this->payout))->assertOk();
        $this->expectException(InvalidArgumentException::class);
        $this->decisions()->retry($correction->fresh(), $this->admin());
    }

    public function test_close_is_refused_while_a_step_is_still_waiting_on_stripe(): void
    {
        $this->stripe->reversalFailures[] = ['Could not reach Stripe. Nothing was lost; it will be tried again.', false];
        $correction = $this->approve();
        $correction->update(['status' => Correction::STATUS_FAILED]);

        $this->expectExceptionMessage('Only a failed correction with no step still waiting on Stripe can be closed. Retry it first.');
        $this->decisions()->close($correction, $this->admin(), 'Give up.');
    }

    public function test_closing_a_failed_redemption_refund_writes_nothing(): void
    {
        $this->stripe->reversalFailures[] = ['Stripe refused the request (example).', true];
        $correction = $this->approve();

        $closed = $this->decisions()->close($correction, $this->admin(), 'The customer was refunded in cash instead.');

        $this->assertSame(Correction::STATUS_CLOSED, $closed->status);
        $this->assertSame(0, CreditLedgerEntry::where('correction_id', $correction->id)->count());
        $this->assertTrue($this->payout->redemption->fresh()->mayAskForRefund(), 'A closed refund no longer counts against the redemption');
        $this->assertLedgerInvariant();
    }

    public function test_a_failed_card_refund_is_retried_with_a_new_key(): void
    {
        $employer = Employer::firstOrFail();
        $this->stripePurchase(20000, 'ch_test_R');
        $this->stripe->refundFailures[] = ['Stripe refused the request (rate_limit).', true];
        $correction = Correction::create(['type' => Correction::TYPE_EMPLOYER_REFUND, 'status' => Correction::STATUS_REQUESTED,
            'open_key' => 'employer:'.$employer->id, 'requested_by_role' => Role::EmployerAdmin->value, 'reason_category' => 'other', 'employer_id' => $employer->id]);

        $correction = $this->decisions()->approveEmployerRefund($correction, $this->admin(), 'Refund.', 5000);
        $this->assertSame(Correction::STATUS_FAILED, $correction->status);

        $this->decisions()->retry($correction, $this->admin());

        $this->assertCount(1, $this->stripe->refunds);
        $this->assertSame(2, count(array_unique(array_filter($this->stripe->idempotencyKeys, fn ($k) => str_starts_with($k, 'lg-correction-')))));
        $this->postWebhook($this->stripeEvent('refund.created', $this->stripe->refunds[0]))->assertOk();
        $this->assertSame(Correction::STATUS_COMPLETED, $correction->fresh()->status);
        $this->assertLedgerInvariant();
    }
}
