<?php

namespace Tests\Feature;

use App\Http\Controllers\Districts\ApplyController;
use App\Models\MerchantApplication;
use App\Services\Districts\MerchantApplications;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesDcAddresses;
use Tests\Concerns\MakesApplications;
use Tests\TestCase;

/**
 * The applicant's private status link (ADR 0031): 40 random characters,
 * only a hash stored, the token after the # (never in a URL the server
 * sees), the same answer for every wrong link, and a step tracker.
 */
class ApplicationStatusLinkTest extends TestCase
{
    use FakesDcAddresses;
    use MakesApplications;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeDcAddresses();
        $this->eligibilityReply();
    }

    public function test_the_token_is_random_and_only_its_hash_is_stored(): void
    {
        [$a, $tokenA] = $this->apply();
        [$b, $tokenB] = $this->apply(['contact_email' => 'b@example.com']);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $tokenA);
        $this->assertNotSame($tokenA, $tokenB);
        $this->assertSame(hash('sha256', $tokenA), $a->status_token_hash);
        $this->assertStringNotContainsString($tokenA, json_encode(MerchantApplication::find($a->id)->getAttributes()));
        $this->assertStringNotContainsString('status_token_hash', json_encode($a->toArray()), 'hidden from serialisation');
        $this->assertSame(url('/apply/status').'#'.$a->id.'.'.$tokenA, MerchantApplications::statusLink($a, $tokenA));
    }

    public function test_the_link_opens_the_status_page_with_the_step_tracker(): void
    {
        [$application, $token] = $this->apply();
        app(MerchantApplications::class)->runCheck($application, $this->staff());

        $this->post('/apply/status', ['link' => "{$application->id}.{$token}"])->assertRedirect('/apply/status');

        $html = $this->get('/apply/status')->assertOk()
            ->assertSee(ApplyController::PROTOTYPE_NOTICE)
            ->assertSee('Kettle &amp; Crumb', false)
            ->assertSee('Step 3 of 5: waiting for BID approval.')
            ->assertSee('aria-current="step"', false)
            ->assertSee('Address inside the district')
            ->getContent();
        $this->assertStringNotContainsString($token, $html, 'the token never appears in the page');
    }

    public function test_a_whole_pasted_link_works_too(): void
    {
        [$application, $token] = $this->apply();

        $this->post('/apply/status', ['link' => MerchantApplications::statusLink($application, $token)])->assertRedirect('/apply/status');
        $this->get('/apply/status')->assertSee('Step 2 of 5');
    }

    public function test_every_wrong_link_gets_the_same_answer(): void
    {
        [$a, $tokenA] = $this->apply();
        [$b] = $this->apply(['contact_email' => 'b@example.com']);

        foreach (["{$b->id}.{$tokenA}", "{$a->id}.".str_repeat('x', 40), "{$a->id}.{$tokenA}x", '999.'.$tokenA, 'nonsense', $tokenA] as $wrong) {
            $this->from('/apply/status')->post('/apply/status', ['link' => $wrong])
                ->assertRedirect('/apply/status')->assertSessionHasErrors(['link' => ApplyController::LINK_NOT_VALID]);
            $this->get('/apply/status')->assertSee('Your status link')->assertDontSee('Step 2 of 5');
        }
    }

    public function test_without_the_link_the_status_page_shows_nothing_and_the_token_is_never_a_route(): void
    {
        $this->apply();

        $this->get('/apply/status')->assertOk()->assertSee('paste the whole link here')->assertDontSee('Kettle');
        foreach (app('router')->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'apply')) {
                $this->assertStringNotContainsString('{', $route->uri(), 'no apply route carries anything in its path');
            }
        }
    }

    public function test_opening_links_is_rate_limited(): void
    {
        foreach (range(1, 10) as $i) {
            $this->post('/apply/status', ['link' => "1.{$i}"]);
        }
        $this->post('/apply/status', ['link' => '1.x'])->assertStatus(429);
    }

    public function test_a_declined_applicant_sees_the_reason(): void
    {
        [$application, $token] = $this->apply();
        app(MerchantApplications::class)->decline($application, $this->staff(), 'This address is a residence, not a shop.');

        $this->post('/apply/status', ['link' => "{$application->id}.{$token}"]);
        $this->get('/apply/status')->assertSee('Step 3 of 5: the BID declined this application.')
            ->assertSee('This address is a residence, not a shop.')
            ->assertSee('S.T. (BID admin)');
    }
}
