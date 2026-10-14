<?php

namespace Tests\Feature;

use Tests\TestCase;

class LandingPageTest extends TestCase
{
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

    public function test_payouts_are_not_described_as_live(): void
    {
        // Stripe Connect isn't built yet: the page must not say merchants are paid out today.
        $this->get('/')->assertOk()
            ->assertDontSee('paid out directly')
            ->assertSee('Stripe Connect (test mode) come in a later build');
    }
}
