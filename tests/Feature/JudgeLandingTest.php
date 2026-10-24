<?php

namespace Tests\Feature;

use App\Enums\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JudgeLandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_landing_page_explains_local_goods_and_numbers_the_tour(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->signIn(Role::Judge);

        $page = $this->get('/judge')->assertOk()
            ->assertSee('Why Local Goods exists')
            ->assertDontSee('What Local Goods is')
            ->assertSee('commute in, sit at a desk, commute out')
            ->assertSee('Redemption Fraud Detection')
            ->assertSeeInOrder(['Employee wallet', 'BID fraud review', 'AI eval results', 'Merchant eligibility check', 'Independent check of the AI', 'Employer dashboard', 'Merchant dashboard'])
            ->assertDontSee('Coming in a later build')
            ->assertSee('Payments and merchant payouts run on Stripe Connect in test mode (real Stripe transactions, no real money)')
            ->assertDontSee('later build')
            ->assertSee('append-only ledger')
            ->assertDontSee('holds and moves the money');

        // The eight tour links all open for a judge.
        preg_match_all('/<a href="([^"]+)" class="tap-target text-primary underline decoration-accent/', $page->getContent(), $links);
        $this->assertCount(8, $links[1]);

        foreach ($links[1] as $link) {
            $this->followingRedirects()->get($link)->assertOk();
        }
    }

    public function test_every_tour_screen_is_built(): void
    {
        $this->signIn(Role::Judge);
        $html = $this->get('/judge')->getContent();

        $this->assertSame(0, substr_count($html, 'Coming in a later build'));
        $this->assertTrue(collect(\App\Http\Controllers\JudgeController::tour())->every('available'));
    }

    public function test_no_adjudication_card_before_a_set_is_published(): void
    {
        $this->signIn(Role::Judge);

        $this->get('/judge')->assertOk()
            ->assertDontSee('id="independent-check-heading"', false)
            ->assertDontSee('Help check the AI');
    }

    public function test_opening_the_landing_page_leaves_view_as_mode(): void
    {
        $this->signIn(Role::Judge);
        $this->get('/judge/view-as/merchant');

        $this->get('/judge')->assertSessionMissing('view_as');
    }
}
