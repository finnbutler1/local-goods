<?php

namespace Tests\Feature;

use App\Http\Controllers\Districts\WhoDoesWhatController;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Who does what" (ADR 0031): eight steps as keyboard tabs that work as
 * links without JavaScript, each naming who handles it, what the software
 * does, what the BID does and a link to the real screen. Linked from the
 * landing page.
 */
class WhoDoesWhatPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_eight_steps_each_with_who_what_the_bid_does_and_the_real_screen(): void
    {
        $html = $this->get('/who-does-what')->assertOk()->assertSee('Who does what')->getContent();

        $this->assertSame(['apply', 'check', 'approve', 'onboard', 'live', 'pay', 'reconcile', 'report'], array_keys(WhoDoesWhatController::STEPS));
        $this->assertSame(8, substr_count($html, 'role="tab"'));
        $this->assertSame(8, substr_count($html, 'role="tabpanel"'));
        foreach (WhoDoesWhatController::STEPS as $step) {
            $this->assertStringContainsString(e($step['bid']), $html);
            $this->assertStringContainsString('href="'.route($step['route']).'"', $html);
        }
        $this->assertStringContainsString('aria-selected="true"', $html);
        $this->assertSame(7, preg_match_all('/role="tabpanel"[^>]*\shidden/', $html), 'one panel shown, seven hidden');
    }

    public function test_a_step_link_works_without_javascript(): void
    {
        $html = $this->get('/who-does-what?step=reconcile')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/id="step-tab-reconcile"[^>]*aria-selected="true"/', $html);
        $this->assertStringContainsString('Local Goods\' platform admin does this, not the BID.', html_entity_decode($html, ENT_QUOTES));
        $this->get('/who-does-what?step=nonsense')->assertOk();
    }

    public function test_the_landing_page_links_to_it_the_map_and_the_application_form(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('href="'.route('who-does-what').'"', false)
            ->assertSee('href="'.route('district').'"', false)
            ->assertSee('href="'.route('apply').'"', false);
    }
}
