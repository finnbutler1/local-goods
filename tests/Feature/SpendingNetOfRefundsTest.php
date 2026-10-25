<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Employer;
use App\Models\MerchantPayout;
use App\Services\BidImpactMetrics;
use App\Services\Corrections\CorrectionDecisions;
use App\Services\EmployerMetrics;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesCorrections;
use Tests\Concerns\UsesFakeStripe;
use Tests\TestCase;

/**
 * Spending figures on the BID impact, participation, employer and landing
 * pages are net of completed refunds (ADR 0028), in the week and month the
 * refund completed. A refund still processing at Stripe changes nothing
 * yet. Display only: the ledger and money logic are untouched.
 */
class SpendingNetOfRefundsTest extends TestCase
{
    use MakesCorrections;
    use RefreshDatabase;
    use UsesFakeStripe;

    protected function setUp(): void
    {
        parent::setUp();
        // A Wednesday in the middle of a month, so the visit and the refund share a week and a month.
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00', 'America/New_York'));
        $this->seed(DatabaseSeeder::class);
        $this->withoutSeededCorrections();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{impact_month: int, impact_all: int, impact_week: int, hr_month: int, hr_week: int, hr_cafe: int, estimate: ?int} */
    private function figures(): array
    {
        $impact = app(BidImpactMetrics::class)->for();
        $hr = app(EmployerMetrics::class)->for(Employer::orderBy('id')->firstOrFail());

        return [
            'impact_month' => $impact['this_month']['spent_cents'],
            'impact_all' => $impact['all_time']['spent_cents'],
            'impact_week' => $impact['weeks'][7]['cents'],
            'hr_month' => $hr['spent_cents'],
            'hr_week' => $hr['weeks'][7]['cents'],
            'hr_cafe' => collect($hr['categories'])->firstWhere('category', 'cafe')['cents'],
            'estimate' => $impact['recirculation_cents'],
        ];
    }

    private function merchantSalesOnParticipation(): string
    {
        $this->signIn(Role::BidAdmin);
        $html = $this->get(route('bid-admin.participation'))->assertOk()->getContent();
        preg_match('/Farragut Grind.*?data-label="Sales this month"[^>]*>(.*?)<\/td>/s', $html, $m);

        return trim(strip_tags($m[1]));
    }

    public function test_a_completed_partial_refund_lowers_every_spending_figure_by_exactly_the_refund(): void
    {
        $redemption = $this->cleared(1250);
        $before = $this->figures();
        $sales = $this->merchantSalesOnParticipation();
        $this->assertStringNotContainsString('net of refunds', $sales);

        app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Charged too much.', amountCents: 450);

        $after = $this->figures();
        foreach (['impact_month', 'impact_all', 'impact_week', 'hr_month', 'hr_week', 'hr_cafe'] as $figure) {
            $this->assertSame($before[$figure] - 450, $after[$figure], $figure);
        }
        $this->assertSame(BidImpactMetrics::recirculationCents($after['impact_all']), $after['estimate'], 'The estimate uses the net total');
        $this->assertSame(app(BidImpactMetrics::class)->totals()['spent_cents'], $after['impact_all'], 'The landing strip reads the same net total');

        $this->assertStringContainsString('net of refunds ($4.50)', $this->merchantSalesOnParticipation());
        $this->get(route('bid-admin.impact'))->assertOk()->assertSee('net of refunds')->assertSee('(net of $4.50 refunded)', false);
        $this->get('/')->assertOk()->assertSee('Spent at independent merchants, net of refunds');
        $this->signIn(Role::EmployerAdmin);
        $this->get(route('employer.dashboard'))->assertOk()->assertSee('Net of refunds')->assertSee('(net of $4.50 refunded)', false);
    }

    public function test_a_refund_comes_off_the_week_it_completed_not_the_week_of_the_visit(): void
    {
        $redemption = $this->cleared(1250);
        $this->travel(7)->days();
        $before = app(BidImpactMetrics::class)->for()['weeks'];

        app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Charged too much.', amountCents: 450);

        $after = app(BidImpactMetrics::class)->for()['weeks'];
        $this->assertSame($before[6]['cents'], $after[6]['cents'], 'The visit\'s week is unchanged');
        $this->assertSame($before[7]['cents'] - 450, $after[7]['cents'], 'This week, when it completed');
        $this->assertSame(450, $after[7]['refunded_cents']);
    }

    public function test_a_refund_still_processing_at_stripe_changes_nothing_until_it_completes(): void
    {
        $this->fakeStripe();
        $this->stripePurchase();
        $this->onboard();
        $redemption = $this->cleared(1250);
        $payout = MerchantPayout::where('redemption_id', $redemption->id)->sole();
        $before = $this->figures();

        app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Wrong size.', amountCents: 450);

        $this->assertSame($before, $this->figures(), 'Processing: not taken off yet');
        $this->signIn(Role::BidAdmin);
        $this->get(route('bid-admin.impact'))->assertOk()->assertDontSee('net of refunds');

        $event = $this->reversedEvent($payout);
        $event['created'] = now()->getTimestamp(); // Stripe dates it; here, the frozen test clock
        $this->postWebhook($event)->assertOk();

        $after = $this->figures();
        $this->assertSame([$before['impact_month'] - 450, $before['hr_week'] - 450], [$after['impact_month'], $after['hr_week']]);
    }

    public function test_visits_and_people_are_not_changed_by_a_refund(): void
    {
        $redemption = $this->cleared(1250);
        $before = app(BidImpactMetrics::class)->for()['this_month'];

        app(CorrectionDecisions::class)->approveRedemptionRefund($this->refundRequest($redemption), $this->admin(), 'Whole order.', amountCents: 1250);

        $after = app(BidImpactMetrics::class)->for()['this_month'];
        $this->assertSame([$before['visits'], $before['merchants'], $before['employees'], $before['employers']],
            [$after['visits'], $after['merchants'], $after['employees'], $after['employers']]);
        $this->assertSame($before['spent_cents'] - 1250, $after['spent_cents']);
    }
}
