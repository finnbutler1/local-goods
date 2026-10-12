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
            ->assertSee("See an employee's wallet", false)
            ->assertSee('See the BID admin view')
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

        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('demo.employee'), '#').'"[^>]*>\s*Log in\s*</a>#', $html);
    }

    public function test_audience_cards_are_employers_then_bid_then_merchants(): void
    {
        $this->get('/')->assertSeeInOrder(['id="aud-employers"', 'id="aud-bid"', 'id="aud-merchants"'], false);
    }

    public function test_trust_section_quotes_the_saved_eval_headline(): void
    {
        $path = sys_get_temp_dir().'/landing-eval-'.uniqid().'.json';
        file_put_contents($path, json_encode(['headline' => [
            'text' => '73% agreement on 11 unseen cases with the current prompt, and 1 suspicious case missed.',
            'sample' => 'Small sample: with 11 cases, one case moves the score by 9.1 points.',
        ]]));
        config(['fraud.eval_latest_path' => $path]);

        try {
            $this->get('/')
                ->assertSee('73% agreement on 11 unseen cases with the current prompt, and 1 suspicious case missed.')
                ->assertSee('one case moves the score by 9.1 points')
                ->assertSee('href="'.route('demo.bid-admin.eval').'"', false)
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

    public function test_impact_figures_are_labeled_as_samples(): void
    {
        $this->get('/')
            ->assertSee('Sample figures — live numbers coming soon.')
            ->assertSee('sample figure, not real data');
    }

    public function test_footer_states_the_contest_and_non_affiliation(): void
    {
        $this->get('/')
            ->assertSee('Demonstration project for the CUA Busch School AI Vibe Coding Contest.')
            ->assertSee('Not affiliated with the Golden Triangle BID.');
    }
}
