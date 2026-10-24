<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Correction;
use App\Models\CreditLedgerEntry;
use App\Models\Employer;
use App\Models\MerchantPayout;
use App\Models\StripeEvent;
use App\Services\Corrections\CorrectionDecisions;
use App\Support\StripeEnvironment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * The webhook events corrections need (ADR 0028), in the exact shape Stripe
 * sent them in the step 7 trial (tests/Fixtures/stripe/corrections), re-tagged
 * as this environment's: transfer.reversed lists every reversal with its
 * metadata, and refund.created / refund.updated carry the refund's status.
 * Each writes its ledger lines once; duplicates, other environments and
 * mismatched amounts write nothing.
 */
class CorrectionWebhookTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;
    use UsesFakeStripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->withoutSeededCorrections();
        $this->fakeStripe();
    }

    /** A trial event (by file number), its object rewritten by $object. */
    private function fixture(string $number, callable $object): array
    {
        $event = json_decode(file_get_contents(glob(__DIR__."/../Fixtures/stripe/corrections/{$number}-*.json")[0]), true);
        $event['id'] = 'evt_test_'.bin2hex(random_bytes(6));
        $event['data']['object'] = $object($event['data']['object']);

        return $event;
    }

    public function test_the_real_transfer_reversed_shape_records_the_refund(): void
    {
        $this->stripePurchase();
        $this->onboard();
        $payout = MerchantPayout::where('redemption_id', $this->cleared(1250)->id)->sole();
        $correction = app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($payout->redemption), $this->admin(), 'Refund.', amountCents: 500);
        $action = $correction->actions->sole();

        $event = $this->fixture('02', function (array $transfer) use ($payout, $action) {
            $transfer['id'] = $payout->stripe_transfer_id;
            $transfer['metadata'] = ['lg_payout_key' => $payout->idempotency_key] + StripeEnvironment::tag();
            $transfer['amount'] = 1250;
            $transfer['reversals']['data'][0] = ['id' => 'trr_test_real_shape', 'amount' => 500, 'metadata' => ['lg_correction_key' => $action->idempotency_key] + StripeEnvironment::tag()]
                + $transfer['reversals']['data'][0];

            return $transfer;
        });
        $this->assertSame(500, $event['data']['object']['amount_reversed'], 'Stripe sends the running total');

        $this->postWebhook($event)->assertOk()->assertJsonPath('outcome', 'refund recorded: trr_test_real_shape');

        $this->assertSame(Correction::STATUS_COMPLETED, $correction->fresh()->status);
        $this->assertSame(3, CreditLedgerEntry::where('correction_id', $correction->id)->count());
        $this->assertSame(MerchantPayout::STATUS_SENT, $payout->fresh()->status);
    }

    public function test_the_real_refund_shapes_record_the_card_refund_once(): void
    {
        $this->stripePurchase(30000, 'ch_test_A');
        $employer = Employer::firstOrFail();
        $request = Correction::create(['type' => Correction::TYPE_EMPLOYER_REFUND, 'status' => Correction::STATUS_REQUESTED, 'open_key' => 'employer:'.$employer->id,
            'requested_by_role' => Role::EmployerAdmin->value, 'reason_category' => 'other', 'employer_id' => $employer->id]);
        $action = app(CorrectionDecisions::class)->approveEmployerRefund($request, $this->admin(), 'Refund.', 800)->actions->sole();
        $ours = fn (array $refund) => ['id' => $action->stripe_id, 'amount' => 800,
            'metadata' => ['lg_correction_key' => $action->idempotency_key] + StripeEnvironment::tag()] + $refund;

        $this->postWebhook($this->fixture('04', $ours))->assertOk()->assertJsonPath('outcome', 'card refund recorded: '.$action->stripe_id);
        $this->postWebhook($this->fixture('06', $ours))->assertOk()->assertJsonPath('outcome', 'card refund already recorded: '.$action->stripe_id);

        $this->assertSame(Correction::STATUS_COMPLETED, $request->fresh()->status);
        $this->assertSame(1, CreditLedgerEntry::where('correction_id', $request->id)->where('type', CreditLedgerEntry::TYPE_EMPLOYER_REFUNDED)->count());
        $this->assertLedgerInvariant();
    }

    public function test_refund_events_that_are_not_ours_change_nothing(): void
    {
        $ledger = CreditLedgerEntry::count();

        $other = $this->fixture('04', fn (array $r) => ['metadata' => ['lg_correction_key' => 'x', 'lg_env' => 'local', 'lg_instance' => 'abcd1234']] + $r);
        $this->postWebhook($other)->assertOk()->assertJsonPath('outcome', 'ignored: other environment');

        $unknown = $this->fixture('04', fn (array $r) => ['metadata' => ['lg_correction_key' => 'not-ours'] + StripeEnvironment::tag()] + $r);
        $this->postWebhook($unknown)->assertOk()->assertJsonPath('outcome', 'ignored: not a Local Goods refund');

        $this->assertSame($ledger, CreditLedgerEntry::count());
        $this->assertSame(1, StripeEvent::count(), 'Only the event about our environment was stored');
    }

    public function test_a_refund_whose_amount_does_not_match_is_refused(): void
    {
        $this->stripePurchase(30000, 'ch_test_A');
        $employer = Employer::firstOrFail();
        $request = Correction::create(['type' => Correction::TYPE_EMPLOYER_REFUND, 'status' => Correction::STATUS_REQUESTED, 'open_key' => 'employer:'.$employer->id,
            'requested_by_role' => Role::EmployerAdmin->value, 'reason_category' => 'other', 'employer_id' => $employer->id]);
        $action = app(CorrectionDecisions::class)->approveEmployerRefund($request, $this->admin(), 'Refund.', 800)->actions->sole();

        $this->postWebhook($this->fixture('04', fn (array $r) => ['id' => $action->stripe_id, 'amount' => 9999, 'metadata' => StripeEnvironment::tag()] + $r))
            ->assertOk()->assertJsonPath('outcome', fn ($o) => str_starts_with($o, 'refused:'));

        $this->assertSame(Correction::STATUS_PROCESSING, $request->fresh()->status);
        $this->assertSame(0, CreditLedgerEntry::where('correction_id', $request->id)->where('type', CreditLedgerEntry::TYPE_EMPLOYER_REFUNDED)->count());
    }
}
