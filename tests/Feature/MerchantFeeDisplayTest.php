<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Employee;
use App\Services\Ai\ClaudeClient;
use App\Services\Corrections\CorrectionDecisions;
use App\Services\PayCodes;
use App\Services\RedemptionService;
use App\Support\MerchantFee;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\SetsMerchantFee;
use Tests\Concerns\UsesFakeStripe;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * Where the merchant fee shows (ADR 0029): the merchant sees the current fee
 * in plain words and Sale / Local Goods fee / You receive on cleared
 * redemptions, Stripe payouts, the pay code review and the CSV. Judges
 * count as the public and never see fee figures. Customers by initials.
 */
class MerchantFeeDisplayTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;
    use SetsMerchantFee;
    use UsesFakeStripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function csv(): array
    {
        return array_map(str_getcsv(...), array_filter(explode("\n", $this->get(route('merchant.redemptions.csv'))->streamedContent())));
    }

    public function test_with_no_fee_the_merchant_reads_none_right_now(): void
    {
        $redemption = $this->cleared(633);
        $this->signIn(Role::Merchant);

        $this->get('/merchant')->assertOk()
            ->assertSee('Local Goods fee: none right now')
            ->assertSee('Local Goods charges no fee on your sales right now.')
            ->assertSeeInOrder(['Sale', '$6.33', 'Local Goods fee', '$0.00', 'You receive', '$6.33'])
            ->assertSee($redemption->employee->initials())
            ->assertDontSee($redemption->employee->display_name);
    }

    public function test_with_a_fee_every_cleared_redemption_shows_sale_fee_and_what_the_merchant_receives(): void
    {
        $this->setMerchantFee(250);
        $this->cleared(633);
        $this->signIn(Role::Merchant);

        $this->get('/merchant')->assertOk()
            ->assertSee('Local Goods fee: 2.5% per sale')
            ->assertSee('Local Goods keeps a fee of 2.5% per sale; Stripe transfers you the rest.')
            ->assertSeeInOrder(['Sale', '$6.33', 'Local Goods fee', '−$0.16', 'You receive', '$6.17']);
        $this->get(route('merchant.stripe.refresh'))->assertOk()->assertSee('Local Goods keeps a fee of 2.5% per sale; Stripe transfers you the rest.');
    }

    public function test_stripe_payout_rows_show_the_three_lines(): void
    {
        $this->fakeStripe();
        $this->stripePurchase(10000);
        $this->onboard();
        $this->setMerchantFee(250, 10);
        $this->cleared(633);
        $this->signIn(Role::Merchant);

        $this->get('/merchant')->assertOk()
            ->assertSeeInOrder(['Payout history', 'Transferred', 'Sale', '$6.33', 'Local Goods fee', '−$0.26', 'You receive', '$6.07']);
    }

    public function test_the_pay_code_review_shows_the_fee_before_confirming(): void
    {
        $this->setMerchantFee(250);
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(5, 'clear'));
        $payCode = app(PayCodes::class)->issue(Employee::orderBy('id')->firstOrFail(), $this->farragut(), 633);
        $this->signIn(Role::Merchant);
        $this->post(route('merchant.pay-code.lookup'), ['pay_code' => $payCode->code]);

        $this->get('/merchant')->assertOk()
            ->assertSeeInOrder(['data-pay-review', 'Sale', '$6.33', 'Local Goods fee', '−$0.16', 'You receive', '$6.17', 'Confirm payment'], false)
            ->assertSee('name="fee_change" value="'.MerchantFee::current()->changeId.'"', false);
    }

    public function test_the_csv_has_the_fee_and_what_the_merchant_receives_with_refunds_negative(): void
    {
        $this->withoutSeededCorrections();
        $this->setMerchantFee(250);
        $redemption = $this->cleared(633);
        $this->travel(5)->minutes();
        app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Part refund.', amountCents: 211);
        $this->signIn(Role::Merchant);

        $rows = $this->csv();
        $this->assertSame(['Total', 'Local Goods fee', 'You receive'], array_slice($rows[0], 8, 3));
        $byKind = collect(array_slice($rows, 1))->where(1, $redemption->reference())->keyBy(3);
        $this->assertSame(['6.33', '0.16', '6.17'], array_slice($byKind['Cleared'], 8, 3));
        $this->assertSame(['-2.11', '-0.05', '-2.06'], array_slice($byKind['Refund'], 8, 3));
        $this->assertSame($redemption->employee->initials(), $byKind['Refund'][4]);
    }

    public function test_held_redemptions_have_no_fee_in_the_csv(): void
    {
        $this->setMerchantFee(250);
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(88, 'hold'));
        $held = app(RedemptionService::class)->redeem($this->shopper(), $this->farragut(), 633);
        $this->signIn(Role::Merchant);

        $row = collect(array_slice($this->csv(), 1))->firstWhere(1, $held->reference());
        $this->assertSame(['6.33', '', ''], array_slice($row, 8, 3));
    }

    public function test_judges_never_see_fee_figures(): void
    {
        $this->withoutSeededCorrections();
        $this->setMerchantFee(250);
        $redemption = $this->cleared(633);
        app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Part refund.', amountCents: 211);
        $this->signIn(Role::Judge);

        $this->get('/merchant')->assertOk()
            ->assertSee('Local Goods fee: on (amounts are shown to Local Goods and BID staff)')
            ->assertSee('Local Goods keeps a fee on each sale; Stripe transfers you the rest.')
            ->assertDontSee('2.5%')
            ->assertDontSee('data-fee-lines', false);
        $this->get(route('bid-admin.corrections'))->assertOk()->assertDontSee('data-fee-returned', false);

        $this->signIn(Role::PlatformAdmin);
        $this->get(route('bid-admin.corrections'))->assertOk()->assertSee('incl. $0.05 Local Goods fee given back');
    }
}
