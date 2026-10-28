<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Merchant;
use App\Models\MerchantApplication as Application;
use App\Models\MerchantApplicationEvent;
use App\Services\Districts\DistrictRules;
use App\Services\Districts\MerchantApplications;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\Concerns\FakesDcAddresses;
use Tests\Concerns\MakesApplications;
use Tests\TestCase;

/**
 * The merchant joining pipeline (ADR 0031): one step at a time, every step
 * logged with who, when and why, declines final and seen by the applicant,
 * approval only inside the district, and only staff decide.
 */
class MerchantApplicationPipelineTest extends TestCase
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

    private function pipeline(): MerchantApplications
    {
        return app(MerchantApplications::class);
    }

    public function test_each_step_moves_one_status_and_logs_who_when_and_why(): void
    {
        [$application, $token] = $this->apply();
        $this->assertSame(Application::APPLIED, $application->status);
        $this->assertNotNull($application->prototype_acknowledged_at);

        $application = $this->pipeline()->runCheck($application, $admin = $this->staff());
        $this->assertSame(Application::ELIGIBILITY_CHECKED, $application->status);
        $this->assertTrue($application->inside_district);
        $this->assertSame('1700 K STREET NW', $application->matched_address);

        $application = $this->pipeline()->approve($application, $admin, 'Independent cafe inside the district.');
        $this->assertSame(Application::BID_APPROVED, $application->status);
        $this->assertSame('Step 4 of 5: approved. Set up payouts with Stripe.', $application->stepSentence());

        $events = $application->events()->get();
        $this->assertSame([[null, 'applied'], ['applied', 'eligibility_checked'], ['eligibility_checked', 'bid_approved']],
            $events->map(fn ($e) => [$e->from_status, $e->to_status])->all());
        $this->assertSame(['applicant', 'staff', 'staff'], $events->pluck('actor')->all());
        $this->assertSame($admin->id, $events[2]->actor_user_id);
        $this->assertSame('Independent cafe inside the district.', $events[2]->reason);
        $this->assertNotNull($events[2]->created_at);
    }

    public function test_steps_cannot_be_skipped(): void
    {
        [$applied] = $this->apply();
        $admin = $this->staff();

        $this->assertRefused(fn () => $this->pipeline()->approve($applied, $admin, 'Skipping the check'), MerchantApplications::CHECK_FIRST);
        $this->assertRefused(fn () => $this->pipeline()->startOnboarding($applied), MerchantApplications::MOVED_ON);

        $checked = $this->pipeline()->runCheck($applied, $admin);
        $this->assertRefused(fn () => $this->pipeline()->startOnboarding($checked), MerchantApplications::MOVED_ON);
        $this->assertFalse($this->pipeline()->goLiveIfReady(new Merchant));

        $approved = $this->pipeline()->approve($checked, $admin, 'Looks right');
        $this->assertRefused(fn () => $this->pipeline()->runCheck($approved, $admin), MerchantApplications::MOVED_ON);
        $this->assertRefused(fn () => $this->pipeline()->approve($approved, $admin, 'Again'), MerchantApplications::MOVED_ON);
        $this->assertRefused(fn () => $this->pipeline()->decline($approved, $admin, 'Too late'), MerchantApplications::MOVED_ON);

        $this->assertSame(3, $approved->events()->count());
    }

    public function test_a_stale_screen_cannot_move_an_application_twice(): void
    {
        $checked = $this->checked();
        $stale = Application::find($checked->id);
        $this->pipeline()->decline($checked, $this->staff(), 'Not independent: part of a group.');

        $this->assertRefused(fn () => $this->pipeline()->approve($stale, $this->staff(), 'Approving from an old tab'), MerchantApplications::MOVED_ON);
        $this->assertSame(Application::DECLINED, $checked->fresh()->status);
    }

    public function test_a_decline_needs_a_reason_is_final_and_the_applicant_sees_it(): void
    {
        [$application] = $this->apply();

        $this->assertRefused(fn () => $this->pipeline()->decline($application, $this->staff(), ' no '), 'Write a reason');

        $declined = $this->pipeline()->decline($application, $this->staff(), 'This address is a residence, not a shop.');
        $this->assertSame(Application::DECLINED, $declined->status);
        $this->assertSame('This address is a residence, not a shop.', $declined->decline_reason);
        $this->assertSame('Step 3 of 5: the BID declined this application.', $declined->stepSentence());

        $this->assertRefused(fn () => $this->pipeline()->runCheck($declined, $this->staff()), MerchantApplications::MOVED_ON);
        $this->assertRefused(fn () => $this->pipeline()->decline($declined, $this->staff(), 'Declined twice'), MerchantApplications::MOVED_ON);
    }

    public function test_an_address_outside_or_not_found_cannot_be_approved(): void
    {
        $outside = $this->checked(['address' => '50 Massachusetts Ave NE']);
        $this->assertFalse($outside->inside_district);
        $this->assertRefused(fn () => $this->pipeline()->approve($outside, $this->staff(), 'Approve anyway'), MerchantApplications::NEEDS_INSIDE);

        $unknown = $this->checked(['address' => '1 Nowhere St NW']);
        $this->assertNull($unknown->inside_district);
        $this->assertRefused(fn () => $this->pipeline()->approve($unknown, $this->staff(), 'Approve anyway'), MerchantApplications::NEEDS_INSIDE);

        // Even a tampered stored answer can't beat the boundary rule on the stored point.
        Application::whereKey($outside->id)->update(['inside_district' => true]);
        $this->assertRefused(fn () => $this->pipeline()->approve($outside->fresh(), $this->staff(), 'Approve anyway'), 'outside the Golden Triangle BID boundary');
    }

    public function test_when_the_address_service_is_down_nothing_changes_and_the_ai_is_not_asked(): void
    {
        [$application] = $this->apply();
        $fake = $this->eligibilityReply();
        $this->fakeDcAddresses(down: true);

        $this->assertRefused(fn () => $this->pipeline()->runCheck($application, $this->staff()), MerchantApplications::ADDRESS_SERVICE_DOWN);
        $this->assertSame(Application::APPLIED, $application->fresh()->status);
        $this->assertCount(0, $fake->calls);
    }

    public function test_only_a_bid_admin_or_the_platform_admin_decides(): void
    {
        foreach ([Role::Judge, Role::Merchant, Role::EmployerAdmin, Role::Employee] as $role) {
            [$application] = $this->apply(['contact_email' => "{$role->value}@example.com"]);
            try {
                $this->pipeline()->runCheck($application, \App\Models\User::factory()->role($role)->create());
                $this->fail("{$role->value} ran the check.");
            } catch (LogicException) {
            }
        }

        $this->assertSame(Application::BID_APPROVED, $this->approved()->status);
        $this->assertSame(Application::ELIGIBILITY_CHECKED, app(MerchantApplications::class)->runCheck($this->apply(['contact_email' => 'p@example.com'])[0], $this->staff(Role::PlatformAdmin))->status);
    }

    public function test_onboarding_makes_a_merchant_approved_inside_but_not_live_until_stripe_says_so(): void
    {
        $approved = $this->approved();

        $user = $this->pipeline()->startOnboarding($approved);
        $application = $approved->fresh();
        $merchant = $application->merchant;

        $this->assertSame(Role::Merchant, $user->role);
        $this->assertSame('owner@example.com', $user->email);
        $this->assertSame(Application::STRIPE_ONBOARDING, $application->status);
        $this->assertSame($user->id, $merchant->user_id);
        $this->assertNotNull($merchant->district_approved_at);
        $this->assertTrue(DistrictRules::isInside($application->district, $merchant->latitude, $merchant->longitude));
        $this->assertNull($merchant->live_at);
        $this->assertSame($user->id, $this->pipeline()->startOnboarding($application)->id, 'pressing again carries on');

        $this->assertFalse($this->pipeline()->goLiveIfReady($merchant), 'not onboarded yet');
        $merchant->forceFill(['stripe_account_id' => 'acct_test_example', 'stripe_transfers_active' => true])->save();
        $this->assertTrue($this->pipeline()->goLiveIfReady($merchant));

        $this->assertSame(Application::LIVE, $application->fresh()->status);
        $this->assertNotNull($merchant->fresh()->live_at);
        $this->assertSame('stripe', MerchantApplicationEvent::latest('id')->first()->actor);
        $this->assertFalse($this->pipeline()->goLiveIfReady($merchant), 'once only');
    }

    public function test_an_email_that_already_has_a_login_is_refused(): void
    {
        $approved = $this->approved(['contact_email' => $this->staff()->email]);

        $this->assertRefused(fn () => $this->pipeline()->startOnboarding($approved), MerchantApplications::EMAIL_TAKEN);
        $this->assertSame(Application::BID_APPROVED, $approved->fresh()->status);
    }

    public function test_steps_are_append_only(): void
    {
        $event = $this->checked()->events()->first();

        try {
            $event->update(['reason' => 'Rewritten']);
            $this->fail('An application step was edited.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $event->delete();
    }

    private function assertRefused(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail("Expected a refusal: {$message}");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
