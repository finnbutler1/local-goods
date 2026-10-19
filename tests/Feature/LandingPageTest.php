<?php

namespace Tests\Feature;

use App\Models\CreditLedgerEntry;
use App\Services\BidImpactMetrics;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_shows_headline_and_demo_links(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Spend local. Prove it.')
            ->assertSee('See the AI eval results')
            ->assertDontSee('/demo/')
            ->assertSee('All businesses, people, and transactions are fictional.')
            ->assertDontSee('public records');
    }

    public function test_hero_has_demo_request_and_log_in_actions(): void
    {
        $html = $this->get('/')
            ->assertSee('Request a demo')
            ->assertSee('href="#request-demo"', false)
            ->assertSee('id="request-demo"', false)
            ->getContent();

        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('login'), '#').'"[^>]*>\s*Log in\s*</a>#', $html);
    }

    public function test_audience_cards_are_employers_then_bid_then_merchants(): void
    {
        $this->get('/')->assertSeeInOrder(['id="aud-employers"', 'id="aud-bid"', 'id="aud-merchants"'], false);
    }

    public function test_trust_section_quotes_the_saved_eval_headline(): void
    {
        $path = sys_get_temp_dir().'/landing-eval-'.uniqid().'.json';
        file_put_contents($path, json_encode(['headline' => [
            'text' => 'Caught 9 of 12 suspicious cases; 9 of 10 holds were suspicious.',
            'sample' => 'Measured on 30 unseen cases, 12 of them labeled suspicious by a person, with the current prompt.',
        ]]));
        config(['fraud.eval_latest_path' => $path]);

        try {
            $this->get('/')
                ->assertSee('Caught 9 of 12 suspicious cases; 9 of 10 holds were suspicious.')
                ->assertSee('12 of them labeled suspicious by a person')
                ->assertSee('href="'.route('eval').'"', false)
                ->assertSee('fails closed')
                ->assertSee('A held redemption is never approved automatically; it waits for review by the BID.');
        } finally {
            unlink($path);
        }
    }

    public function test_trust_section_says_so_when_there_are_no_eval_results(): void
    {
        config(['fraud.eval_latest_path' => sys_get_temp_dir().'/missing-'.uniqid().'.json']);

        $this->get('/')->assertOk()->assertSee('Eval results not yet published.');
    }

    public function test_impact_is_one_labeled_illustrative_scenario_with_visible_arithmetic(): void
    {
        $this->get('/')
            ->assertSee('Illustrative scenario')
            ->assertSeeInOrder(['2,000', 'employees', '×', '$50', 'per month', '×', '12', 'months', '=', '$1.2M', 'a year'])
            ->assertSee('$1.2M a year spent at independent district businesses')
            ->assertDontSee('of employees redeeming')
            ->assertDontSee('neighborhood visits per employee')
            ->assertDontSee('independent merchants accepting credits');
    }

    public function test_footer_states_the_contest_and_non_affiliation(): void
    {
        $this->get('/')
            ->assertSee('Demonstration project for the CUA Busch School AI Vibe Coding Contest.')
            ->assertSee('Not affiliated with the Golden Triangle BID.');
    }

    public function test_stripe_is_described_as_test_mode_neither_missing_nor_live(): void
    {
        // Stripe Connect is built, in test mode only (ADR 0015).
        $this->get('/')->assertOk()
            ->assertSee('Payments and merchant payouts run on Stripe in test mode: real Stripe transactions, no real money.')
            ->assertDontSee('later build')
            ->assertDontSee('paid out directly');
    }

    public function test_impact_strip_shows_the_ledger_totals_labelled_as_fictional_demo_data(): void
    {
        $this->seed(DatabaseSeeder::class);
        $totals = app(BidImpactMetrics::class)->totals();
        $this->assertSame((int) CreditLedgerEntry::where('type', CreditLedgerEntry::TYPE_MERCHANT_EARNED)->sum('amount_cents'), $totals['spent_cents']);

        $html = $this->get('/')
            ->assertSee('The demo program so far')
            ->assertSee('Demo data: all people and transactions are fictional.')
            ->assertSeeInOrder([
                'Spent at independent merchants', Money::format($totals['spent_cents']),
                'Merchants reached', (string) $totals['merchants'],
                'Employees participating', (string) $totals['employees'],
                'Employers participating', (string) $totals['employers'],
            ])
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/\\b3\\s?x\\b|3×|multiplier/i', strip_tags($html));
    }

    public function test_impact_strip_names_no_merchant_or_real_candidate_to_logged_out_visitors(): void
    {
        $this->seed(DatabaseSeeder::class);
        $strip = str($this->get('/')->getContent())->after('data-demo-totals')->before('</section>')->toString();

        foreach (DB::table('merchants')->pluck('name') as $name) {
            $this->assertStringNotContainsString(e($name), $strip);
        }
        $page = mb_strtolower($this->get('/')->getContent());
        foreach (array_slice(array_map('str_getcsv', file(base_path('data/merchant_candidates.csv'), FILE_IGNORE_NEW_LINES)), 1) as $row) {
            $this->assertStringNotContainsString(mb_strtolower(e($row[1])), $page, 'Real candidate '.$row[0].' must not be on the public page');
        }
        $this->get('/')->assertDontSee('public records');
    }

    public function test_impact_strip_has_an_empty_state(): void
    {
        $this->get('/')->assertOk()->assertSee('No cleared redemptions yet.');
    }
}
