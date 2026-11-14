<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Http\Controllers\Projections\BusinessModelController;
use App\Models\User;
use App\Support\Projections\BusinessModel;
use App\Support\Projections\Figures;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Business model page and its CSV (ADR 0030): signed-in reviewers, BID
 * admin and platform admin only, every screen labelled "Projection, not
 * results", every figure from the calculator.
 */
class BusinessModelPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private static function text(string $html): string
    {
        return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));
    }

    public function test_logged_out_visitors_are_sent_to_log_in(): void
    {
        $this->get('/business-model')->assertRedirect(route('login'));
        $this->get('/business-model.csv')->assertRedirect(route('login'));
    }

    public function test_reviewers_and_admins_can_open_it_and_no_one_else(): void
    {
        foreach ([Role::Judge, Role::BidAdmin, Role::PlatformAdmin] as $role) {
            $this->actingAs($this->signIn($role))->get('/business-model')->assertOk();
            $this->actingAs($this->signIn($role))->get('/business-model.csv')->assertOk();
        }
        foreach ([Role::Employee, Role::EmployerAdmin, Role::Merchant] as $role) {
            $this->actingAs($this->signIn($role))->get('/business-model')->assertForbidden();
            $this->actingAs($this->signIn($role))->get('/business-model.csv')->assertForbidden();
        }

        $public = User::factory()->role(Role::Judge)->create(['reviewer_type' => ReviewerType::Public]);
        $this->actingAs($public)->get('/business-model')->assertOk();

        $contact = User::factory()->role(Role::Judge)->create(['reviewer_type' => ReviewerType::PersonalContact]);
        $this->actingAs($contact)->get('/business-model')->assertForbidden();
    }

    public function test_the_makes_money_box_is_first_and_its_fee_sentence_follows_the_setting(): void
    {
        foreach ([false, true] as $on) {
            config(['payments.employer_platform_fee_enabled' => $on]);
            $html = $this->actingAs($this->signIn(Role::BidAdmin))->get('/business-model')->assertOk()->getContent();
            $box = substr($html, strpos($html, 'data-how-it-makes-money'));
            $box = substr($box, 0, strpos($box, '</section>'));
            $text = self::text($box);

            $this->assertLessThan(strpos($html, 'id="choose-heading"'), strpos($html, 'data-how-it-makes-money'), 'The box sits above the scenario choice.');
            $this->assertStringContainsString('How Local Goods makes money', $text);
            $this->assertStringContainsString('Projection, not results', $text);
            $this->assertStringContainsString('href="#assumptions"', $box);
            $this->assertStringContainsString('would receive a share of the employer fee (planned; no money goes to a BID today)', $text);
            $this->assertStringContainsString('refunded if the employer asks', $text);
            $this->assertStringContainsString($on ? 'switched on, with payments simulated: no real money' : 'built and tested; switched off during the pilot', $text);
            $this->assertStringContainsString('Small (under 1,000 member businesses) $1,000 a year; Medium (1,000 to 3,999 member businesses) $2,500 a year; and Large (4,000 or more member businesses) $5,000 a year', $text);
            $this->assertStringContainsString('Every district pays from its program start date, with no free pilot year.', $text);
            $this->assertStringNotContainsString('month 13', $text);
            $this->assertStringContainsString('already pay card processing', $text);
            $this->assertStringContainsString('may belong to the employer, the employee or the state (unclaimed property)', $text);
            $this->assertStringContainsString('Local Goods charges no dormancy fees.', $text);
            $this->assertStringNotContainsString('start-up fee', $text);
            $this->assertStringNotContainsString($on ? 'switched off during the pilot' : 'no real money', $text);
        }
    }

    public function test_the_bid_program_fee_sentence_says_planned_until_it_charges_in_stripe_test_mode(): void
    {
        $box = function (): string {
            $html = $this->actingAs($this->signIn(Role::BidAdmin))->get('/business-model')->assertOk()->getContent();
            $box = substr($html, strpos($html, 'data-bid-program-fee'));

            return self::text(substr($box, 0, strpos($box, '</li>')));
        };

        $this->assertStringContainsString('Districts would pay an annual program fee.', $box());
        $this->assertStringContainsString('The BID program fee is planned, not charged in the app', $box());

        config(['payments.bid_program_fee_enabled' => true]);
        $this->assertStringContainsString('so nothing is charged', $box(), 'On, but no Stripe test keys: still not charged.');

        config(['services.stripe.key' => 'pk_test_fake', 'services.stripe.secret' => 'sk_test_fake', 'services.stripe.webhook_secret' => 'whsec_test_fake']);
        $this->assertStringContainsString('Districts pay an annual program fee.', $box());
        $this->assertStringContainsString('The BID program fee is charged in Stripe test mode: no real money', $box());
        $this->assertStringNotContainsString('planned, not charged', $box());
    }

    public function test_the_unspent_credit_estimate_sits_under_revenue_and_says_it_is_not_counted(): void
    {
        $html = $this->actingAs($this->signIn(Role::Judge))->get('/business-model?scenario=a&case=base')->assertOk()->getContent();

        preg_match('/<tr\b[^>]*data-unspent-estimate\b[^>]*>.*?<\/tr>/s', $html, $row);
        $this->assertNotEmpty($row, 'The P&L has the estimate row.');
        $this->assertStringContainsString('Unspent credits (estimate, not counted as revenue)', $row[0]);
        $this->assertLessThan(strpos($html, 'data-unspent-estimate'), strpos($html, '>Revenue</th>'), 'It comes right after the revenue total.');

        $note = self::text(substr($html, strpos($html, 'data-unspent-estimate-note')));
        $this->assertStringStartsWith('Unspent credits (estimate, not counted as revenue): 85% spent in the month it\'s given; about 8% never spent. The rest is spent in later months.', trim(substr($note, strpos($note, 'Unspent'))));
        $this->assertStringContainsString('Local Goods charges no dormancy fees.', $note);
    }

    public function test_it_is_labelled_a_projection_and_links_its_assumptions(): void
    {
        $html = $this->actingAs($this->signIn(Role::Judge))->get('/business-model')->getContent();

        $this->assertGreaterThanOrEqual(3, substr_count($html, 'Projection, not results'));
        $this->assertStringContainsString('href="#assumptions"', $html);
        $this->assertStringContainsString('id="assumptions"', $html);
        $this->assertStringContainsString('Assumption: to validate in customer discovery', $html);
        $this->assertStringContainsString('a 2013 prepaid-card model shared by a mentor', $html);
        $this->assertStringContainsString('never revenue', $html);
        $this->assertStringContainsString(route('business-model.csv'), $html);
    }

    public function test_the_figures_come_from_the_calculator_for_each_scenario_and_case(): void
    {
        $model = BusinessModel::fromConfig();
        $judge = $this->signIn(Role::Judge);

        foreach (['a', 'b', 'c'] as $scenario) {
            foreach (['low', 'base', 'high'] as $case) {
                $p = $model->run($scenario, $case);
                $text = self::text($this->actingAs($judge)->get("/business-model?scenario={$scenario}&case={$case}")->assertOk()->getContent());

                foreach ($p->years() as $year) {
                    $this->assertStringContainsString(Figures::dollars($year['revenue']), $text, "{$scenario} {$case}");
                    $this->assertStringContainsString(Figures::dollars($year['ebitda']), $text, "{$scenario} {$case}");
                }
                $this->assertStringContainsString(Figures::dollars($p->peakCashNeeded()['amount']), $text);
                $this->assertStringContainsString($p->breakEvenMonth() === null ? 'Not reached' : 'Month '.$p->breakEvenMonth(), $text);
            }
        }
    }

    public function test_what_it_takes_is_shown_under_break_even(): void
    {
        $needs = BusinessModel::fromConfig()->whatItTakes('base');
        $text = self::text($this->actingAs($this->signIn(Role::BidAdmin))->get('/business-model')->getContent());

        $this->assertStringContainsString('What it takes', $text);
        $this->assertStringContainsString(Figures::count($needs->employees).' participating employees', $text);
        $this->assertStringContainsString('That is '.Figures::count($needs->employers).' employers', $text);
        $this->assertStringContainsString('An employer platform fee of '.Figures::exact($needs->breakEvenFee), $text);
        $this->assertLessThan(strpos($text, 'What it takes'), strpos($text, 'Monthly break-even'));
    }

    public function test_the_csv_has_the_label_every_scenario_case_and_year_and_what_it_takes(): void
    {
        $csv = $this->actingAs($this->signIn(Role::Judge))->get('/business-model.csv')->assertOk()->streamedContent();
        $rows = array_map(fn ($line) => str_getcsv($line, escape: ''), array_filter(explode("\n", trim($csv))));

        $this->assertStringStartsWith('Projection, not results', $rows[0][0]);
        $this->assertSame(BusinessModelController::CSV_HEADERS, $rows[1]);

        $yearly = BusinessModelController::rows(BusinessModel::fromConfig());
        $this->assertCount(3 * 3 * 4, $yearly);
        foreach ($yearly as $i => $row) {
            $this->assertSame(array_map('strval', $row), $rows[$i + 2]);
        }

        $this->assertStringContainsString('What it takes', $csv);
        $needs = BusinessModel::fromConfig()->whatItTakes('base');
        $this->assertStringContainsString(',base,'.$needs->employees.','.$needs->employers.',', $csv);
        $this->assertStringContainsString(number_format($needs->breakEvenFee, 2), $csv);
    }

    public function test_reviewers_find_it_from_their_home_and_admins_from_theirs(): void
    {
        $this->actingAs($this->signIn(Role::Judge))->get('/judge')->assertOk()->assertSee(route('business-model'), false);
        $this->actingAs($this->signIn(Role::PlatformAdmin))->get('/admin')->assertOk()->assertSee(route('business-model'), false);
        $this->actingAs($this->signIn(Role::BidAdmin))->get('/bid-admin/impact')->assertOk()->assertSee(route('business-model'), false);
        $this->get('/')->assertDontSee(route('business-model'), false);
    }
}
