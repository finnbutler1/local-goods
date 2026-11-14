<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\BidProgramFeePayment;
use App\Models\CreditLedgerEntry;
use App\Models\StripeEvent;
use App\Models\User;
use App\Services\Payments\BidProgramFeeCheckout;
use App\Support\StripeEnvironment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\PaysBidProgramFee;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * Paying the BID annual program fee in Stripe test mode (ADR 0044): the
 * server sets the price from the tier, one payment per district per program
 * year whatever the browser does, and the webhook records it once, only on
 * a verified success that matches the payment. Judges never pay.
 */
class BidProgramFeeChargeTest extends TestCase
{
    use PaysBidProgramFee;
    use RefreshDatabase;
    use UsesFakeStripe;

    private User $bidAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeStripe();
        $this->switchOnBidFee();
        $this->setUpDistrict(6000, '2026-10-01');
        $this->travelTo(Carbon::parse('2026-11-13 12:00', 'America/New_York'));
        $this->bidAdmin = $this->signIn(Role::BidAdmin);
    }

    private function pay(array $input = [])
    {
        return $this->actingAs($this->bidAdmin)->post(route('bid-admin.program-fee.pay'), $input);
    }

    private function feeLines(): int
    {
        return CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_BID_PROGRAM_FEE)->count();
    }

    public function test_paying_opens_checkout_at_the_tiers_price_whatever_the_browser_sends(): void
    {
        $this->get(route('bid-admin.program-fee'))->assertOk()->assertSee('data-pay-program-fee', false)->assertSee('$5,000.00 a year');

        $response = $this->pay(['amount_cents' => 1, 'amount' => '1.00', 'tier' => 'Small', 'program_year' => 9]);

        $payment = BidProgramFeePayment::sole();
        $response->assertRedirect('https://checkout.stripe.test/'.$payment->stripe_checkout_session_id);
        $this->assertSame([500000, 'Large', 1, 'usd', BidProgramFeePayment::STATUS_PENDING], [$payment->amount_cents, $payment->tier, $payment->program_year, $payment->currency, $payment->status]);
        $this->assertSame(['2026-10-01', '2027-09-30'], [$payment->period_starts_on->format('Y-m-d'), $payment->period_ends_on->format('Y-m-d')]);

        [$params] = $this->stripe->callsOf('checkout');
        $this->assertSame('payment', $params['mode']);
        $this->assertArrayNotHasKey('payment_method_types', $params, 'Card, Apple Pay and Google Pay come from the Stripe Dashboard setting.');
        $this->assertSame(500000, $params['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame(BidProgramFeeCheckout::MESSAGE, $params['custom_text']['submit']['message']);
        $this->assertSame($payment->payment_token, $params['payment_intent_data']['metadata']['lg_bid_fee_token']);
        $this->assertSame(['lg_bid_fee_id' => (string) $payment->id, 'lg_district_id' => (string) $payment->district_id, 'lg_program_year' => '1'] + StripeEnvironment::tag(),
            array_diff_key($params['metadata'], ['lg_bid_fee_token' => 1]));
        $this->assertSame(0, $this->feeLines(), 'Nothing reaches the ledger until Stripe confirms.');
    }

    public function test_double_clicks_two_tabs_and_retries_reuse_one_payment_and_one_checkout(): void
    {
        $first = $this->pay()->headers->get('Location');
        $second = $this->pay()->headers->get('Location');
        $third = $this->pay()->headers->get('Location');

        $this->assertSame($first, $second);
        $this->assertSame($first, $third);
        $this->assertSame(1, BidProgramFeePayment::count());
        $this->assertCount(1, $this->stripe->callsOf('checkout'), 'One Checkout, so one possible charge.');

        $this->payBidFee(BidProgramFeePayment::sole())->assertOk();
        $this->assertSame(1, $this->feeLines());

        $this->pay()->assertRedirect(route('bid-admin.program-fee'))->assertSessionHas('error', fn ($m) => str_contains($m, 'already paid'));
        $this->assertCount(1, $this->stripe->callsOf('checkout'));
        $this->assertSame(1, BidProgramFeePayment::count());
    }

    public function test_an_expired_checkout_never_blocks_the_year_and_it_is_still_paid_once(): void
    {
        $this->pay();
        $payment = BidProgramFeePayment::sole();
        $oldToken = $payment->payment_token;
        $oldSession = $payment->stripe_checkout_session_id;

        // Stripe expires it and says so.
        $this->stripe->sessions[$oldSession]['status'] = 'expired';
        $this->postWebhook($this->stripeEvent('checkout.session.expired', ['id' => $oldSession, 'object' => 'checkout.session']))->assertOk()
            ->assertJsonPath('outcome', 'checkout expired: BID program fee #'.$payment->id.' stays due');
        $this->assertNotSame($oldToken, $payment->fresh()->payment_token);

        $this->pay()->assertRedirect();
        $payment->refresh();
        $this->assertNotSame($oldSession, $payment->stripe_checkout_session_id, 'A fresh Checkout.');
        $this->assertSame(1, BidProgramFeePayment::count());

        $this->payBidFee($payment)->assertJsonPath('outcome', 'paid: BID program fee #'.$payment->id);
        $this->assertTrue($payment->fresh()->isPaid());
        $this->assertSame(1, $this->feeLines());
    }

    public function test_an_expired_checkout_found_on_the_next_press_is_replaced(): void
    {
        $this->pay();
        $payment = BidProgramFeePayment::sole();
        $this->stripe->sessions[$payment->stripe_checkout_session_id]['status'] = 'expired';

        $this->pay()->assertRedirect();
        $this->assertCount(2, $this->stripe->callsOf('checkout'));
        $this->payBidFee($payment->fresh())->assertJsonPath('outcome', 'paid: BID program fee #'.$payment->id);
        $this->assertSame(1, $this->feeLines());
    }

    public function test_a_failed_card_leaves_the_year_due_and_a_retry_pays_once(): void
    {
        $this->pay();
        $payment = BidProgramFeePayment::sole();

        $this->postWebhook($this->stripeEvent('payment_intent.payment_failed', $this->bidFeeIntent($payment, ['status' => 'requires_payment_method', 'last_payment_error' => ['message' => 'Your card was declined.']])))
            ->assertJsonPath('outcome', 'payment failed: BID program fee #'.$payment->id.' stays due');
        $this->get(route('bid-admin.program-fee'))->assertSee('Your card was declined.')->assertSee('data-pay-program-fee', false);

        $this->pay();
        $this->assertCount(1, $this->stripe->callsOf('checkout'), 'The open Checkout is reused for the retry.');
        $this->payBidFee($payment->fresh());
        $this->assertTrue($payment->fresh()->isPaid());
        $this->assertNull($payment->fresh()->payment_error);
        $this->assertSame(1, $this->feeLines());
    }

    public function test_a_complete_checkout_means_a_payment_is_on_its_way_and_no_second_one_starts(): void
    {
        $this->pay();
        $payment = BidProgramFeePayment::sole();
        $this->stripe->completeSession($payment->stripe_checkout_session_id);

        $this->pay()->assertSessionHas('error', fn ($m) => str_contains($m, 'Stripe already has the payment'));
        $this->assertCount(1, $this->stripe->callsOf('checkout'));
    }

    public function test_the_webhook_records_once_and_a_replay_changes_nothing(): void
    {
        $this->pay();
        $payment = BidProgramFeePayment::sole();
        $pi = $this->bidFeeIntent($payment);
        $event = $this->stripeEvent('payment_intent.succeeded', $pi);

        $this->postWebhook($event)->assertOk()->assertJsonPath('outcome', 'paid: BID program fee #'.$payment->id);
        $this->postWebhook($event)->assertOk()->assertJsonPath('outcome', 'duplicate: already handled, nothing changed');
        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $pi))->assertJsonPath('outcome', 'already paid: BID program fee #'.$payment->id);

        $line = CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_BID_PROGRAM_FEE)->sole();
        $this->assertTrue($line->platform);
        $this->assertSame([500000, $payment->id, null, null, null], [$line->amount_cents, $line->bid_program_fee_payment_id, $line->employer_id, $line->employee_id, $line->merchant_id]);
        $payment->refresh();
        $this->assertSame([$pi['id'], $pi['latest_charge']], [$payment->stripe_payment_intent_id, $payment->stripe_charge_id]);
        $this->assertNotNull($payment->paid_at);
    }

    public function test_a_bad_signature_is_refused_and_records_nothing(): void
    {
        $this->pay();
        $payment = BidProgramFeePayment::sole();

        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $this->bidFeeIntent($payment)), 't='.time().',v1=not-a-real-signature')->assertStatus(400);
        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $this->bidFeeIntent($payment)), $this->signedHeader('{}', 'whsec_example_wrong_secret'))->assertStatus(400);

        $this->assertTrue($payment->fresh()->isPending());
        $this->assertSame(0, $this->feeLines());
        $this->assertSame(0, StripeEvent::count());
    }

    public function test_a_payment_that_does_not_match_records_nothing(): void
    {
        $this->pay();
        $payment = BidProgramFeePayment::sole();

        foreach ([
            'amount' => [['amount' => 100000, 'amount_received' => 100000], []],
            'currency' => [['currency' => 'eur'], []],
            'payment id' => [[], ['lg_bid_fee_id' => '999']],
            'district' => [[], ['lg_district_id' => '999']],
            'program year' => [[], ['lg_program_year' => '2']],
        ] as $what => [$changes, $metadata]) {
            $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $this->bidFeeIntent($payment, $changes, $metadata)))->assertOk()
                ->assertJsonPath('outcome', fn ($o) => str_starts_with($o, 'refused: '));
            $this->assertTrue($payment->fresh()->isPending(), $what);
            $this->assertSame(0, $this->feeLines(), $what);
        }

        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $this->bidFeeIntent($payment, [], ['lg_env' => 'other-env', 'lg_instance' => 'deadbeef'])))
            ->assertJsonPath('outcome', 'ignored: other environment');
        $this->assertSame(0, $this->feeLines());
    }

    public function test_a_payment_for_an_old_token_after_the_year_is_paid_records_nothing(): void
    {
        $this->pay();
        $payment = BidProgramFeePayment::sole();
        $stale = $this->bidFeeIntent($payment);
        $this->stripe->sessions[$payment->stripe_checkout_session_id]['status'] = 'expired';
        $this->pay();
        $this->payBidFee($payment->fresh());

        $this->postWebhook($this->stripeEvent('payment_intent.succeeded', $stale))->assertJsonPath('outcome', 'ignored: not a current BID program fee payment (see reconciliation)');
        $this->assertSame(1, $this->feeLines());
    }

    public function test_the_next_program_year_is_a_new_payment(): void
    {
        $this->pay();
        $this->payBidFee(BidProgramFeePayment::sole());

        $this->travelTo(Carbon::parse('2027-10-02 12:00', 'America/New_York'));
        $this->pay()->assertRedirect();
        $this->assertSame([1, 2], BidProgramFeePayment::orderBy('program_year')->pluck('program_year')->all());
        $this->payBidFee(BidProgramFeePayment::where('program_year', 2)->sole());
        $this->assertSame(2, $this->feeLines());
    }

    public function test_the_demo_bid_admin_pays_up_to_the_demo_cap(): void
    {
        $demo = User::factory()->role(Role::BidAdmin)->create(['email' => config('demo.accounts.bid_admin.email')]);

        config(['demo.bid_program_fee_cap_cents' => 100000]);
        $this->actingAs($demo)->post(route('bid-admin.program-fee.pay'))->assertSessionHas('error', fn ($m) => str_contains($m, 'demo BID admin can pay up to $1,000.00'));
        $this->assertSame(0, BidProgramFeePayment::count());

        config(['demo.bid_program_fee_cap_cents' => 500000]);
        $this->actingAs($demo)->post(route('bid-admin.program-fee.pay'))->assertRedirect();
        $this->assertSame(1, BidProgramFeePayment::count());
    }

    public function test_judges_and_the_public_judge_see_no_button_or_amounts_and_cannot_pay(): void
    {
        $public = User::factory()->role(Role::Judge)->create();
        $public->forceFill(['reviewer_type' => ReviewerType::Public])->save();

        foreach ([$this->signIn(Role::Judge), $public] as $judge) {
            $html = $this->actingAs($judge)->get(route('bid-admin.program-fee'))->assertOk()
                ->assertDontSee('data-pay-program-fee', false)->assertDontSee('data-district-program-form', false)
                ->assertSee('Large')->getContent();
            $this->assertStringNotContainsString('$', strip_tags($html));

            $this->actingAs($judge)->post(route('bid-admin.program-fee.pay'))->assertForbidden();
        }
        $this->assertSame(0, BidProgramFeePayment::count());
        $this->assertSame([], $this->stripe->callsOf('checkout'));
    }

    public function test_the_platform_admin_neither_pays_nor_refunds_and_only_writes_a_note(): void
    {
        $this->pay();
        $payment = BidProgramFeePayment::sole();
        $this->payBidFee($payment);
        $before = CreditLedgerEntry::count();

        $admin = $this->signIn(Role::PlatformAdmin);
        $this->post(route('bid-admin.program-fee.pay'))->assertForbidden();
        $this->post(route('bid-admin.program-fee.note', $payment), ['note' => 'Asked about a refund; none is made in the app.'])->assertRedirect()->assertSessionHas('status');
        $payment->refresh();
        $this->assertSame(['Asked about a refund; none is made in the app.', $admin->id], [$payment->admin_note, $payment->admin_note_by_user_id]);
        $this->assertTrue($payment->isPaid());
        $this->assertSame($before, CreditLedgerEntry::count(), 'A note moves no money.');

        $this->actingAs($this->bidAdmin)->post(route('bid-admin.program-fee.note', $payment), ['note' => 'Mine'])->assertForbidden();
        $this->assertSame([], array_filter(app('router')->getRoutes()->getRoutesByName(), fn ($r, $name) => str_contains($name, 'program-fee') && str_contains($name, 'refund'), ARRAY_FILTER_USE_BOTH));
    }

    public function test_a_refund_made_in_the_stripe_dashboard_is_not_recorded_by_the_app(): void
    {
        $this->pay();
        $payment = BidProgramFeePayment::sole();
        $this->payBidFee($payment);
        $pi = $payment->fresh()->stripe_payment_intent_id;
        $before = CreditLedgerEntry::count();

        $refund = ['id' => 're_test_dashboard', 'object' => 'refund', 'amount' => 500000, 'currency' => 'usd', 'payment_intent' => $pi, 'status' => 'succeeded', 'metadata' => []];
        $this->postWebhook($this->stripeEvent('refund.created', $refund))->assertOk();
        $this->postWebhook($this->stripeEvent('charge.refunded', ['id' => 'ch_x', 'object' => 'charge', 'payment_intent' => $pi, 'amount_refunded' => 500000, 'refunded' => true, 'metadata' => []]))
            ->assertJsonPath('outcome', 'ignored: not an event Local Goods uses');

        $this->assertTrue($payment->fresh()->isPaid());
        $this->assertSame($before, CreditLedgerEntry::count());
    }
}
