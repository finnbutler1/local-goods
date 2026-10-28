<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\MerchantApplication;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesDcAddresses;
use Tests\Concerns\MakesApplications;
use Tests\TestCase;

/**
 * The BID admin's Applications queue (ADR 0031): run the check, see the
 * AI suggestion and the map, approve or decline with a reason. Judges read
 * it with the email masked and every button disabled.
 */
class ApplicationQueueTest extends TestCase
{
    use FakesDcAddresses;
    use MakesApplications;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeDcAddresses();
        $this->eligibilityReply('independent', 0.92);
    }

    public function test_the_bid_admin_checks_sees_the_suggestion_and_approves(): void
    {
        [$application] = $this->apply();
        $this->signIn(Role::BidAdmin);

        $this->get('/bid-admin/applications')->assertOk()->assertSee('Kettle &amp; Crumb', false)->assertSee('Not checked yet');
        $html = $this->get("/bid-admin/applications/{$application->id}")->assertOk()->assertSee('Run the eligibility check')->getContent();
        $this->assertStringContainsString('owner@example.com', str_replace('<wbr>', '', $html));

        $this->post("/bid-admin/applications/{$application->id}/check")->assertRedirect("/bid-admin/applications/{$application->id}")
            ->assertSessionHas('status');

        $this->get("/bid-admin/applications/{$application->id}")->assertOk()
            ->assertSee('Inside the Golden Triangle BID: DC matched "1700 K STREET NW".', false)
            ->assertSee('data-map-pin="applicant"', false)
            ->assertSee('Independent (looks eligible)')
            ->assertSee('92% (high)')
            ->assertSee('Suggestion only: a BID admin decides.')
            ->assertSee('Approve for the district');
        $this->assertSame(MerchantApplication::ELIGIBILITY_CHECKED, $application->fresh()->status, 'the AI did not approve');

        $this->post("/bid-admin/applications/{$application->id}/approve", ['reason' => 'Independent cafe, inside the district.'])
            ->assertSessionHas('status');
        $this->assertSame(MerchantApplication::BID_APPROVED, $application->fresh()->status);
    }

    public function test_a_decline_needs_a_reason(): void
    {
        [$application] = $this->apply();
        $this->signIn(Role::BidAdmin);

        $this->post("/bid-admin/applications/{$application->id}/decline", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/bid-admin/applications/{$application->id}/decline", ['reason' => 'A residence, not a shop.'])->assertSessionHas('status');
        $this->assertSame(MerchantApplication::DECLINED, $application->fresh()->status);
    }

    public function test_approving_before_the_check_is_refused_with_a_message(): void
    {
        [$application] = $this->apply();
        $this->signIn(Role::BidAdmin);

        $this->post("/bid-admin/applications/{$application->id}/approve", ['reason' => 'Skip the check'])
            ->assertSessionHas('error', 'Run the eligibility check before approving: no step is skipped.');
        $this->assertSame(MerchantApplication::APPLIED, $application->fresh()->status);
    }

    public function test_judges_read_it_with_the_email_masked_and_cannot_act(): void
    {
        $checked = $this->checked();
        $this->signIn(Role::Judge);

        $html = $this->get("/bid-admin/applications/{$checked->id}")->assertOk()
            ->assertSee('Read-only.')
            ->assertSee('o…@example.com')
            ->getContent();
        $this->assertStringNotContainsString('owner@example.com', str_replace('<wbr>', '', $html));
        $this->assertMatchesRegularExpression('/<fieldset class="contents" disabled/', $html);

        foreach (['check', 'approve', 'decline'] as $action) {
            $this->post("/bid-admin/applications/{$checked->id}/{$action}", ['reason' => 'Trying as a judge'])->assertForbidden();
        }
        $this->assertSame(MerchantApplication::ELIGIBILITY_CHECKED, $checked->fresh()->status);
    }

    public function test_the_queue_is_for_bid_and_platform_admins_only(): void
    {
        foreach ([Role::Employee, Role::Merchant, Role::EmployerAdmin] as $role) {
            $this->signIn($role);
            $this->get('/bid-admin/applications')->assertForbidden();
        }
        $this->signIn(Role::PlatformAdmin);
        $this->get('/bid-admin/applications')->assertOk();
        // The platform admin's own menu has no Applications item: it is linked from both dashboards.
        $this->get('/admin')->assertSee('href="'.route('bid-admin.applications').'"', false);
        $this->get('/bid-admin')->assertSee('href="'.route('bid-admin.applications').'"', false)
            ->assertSee('href="'.route('bid-admin.partnership').'"', false);
    }
}
