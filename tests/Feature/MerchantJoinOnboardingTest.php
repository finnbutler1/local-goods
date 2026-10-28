<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Controllers\Districts\ApplyController;
use App\Models\Employee;
use App\Models\MerchantApplication;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\RedemptionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesDcAddresses;
use Tests\Concerns\MakesApplications;
use Tests\Concerns\UsesFakeStripe;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * Joining end to end (ADR 0031), with fake Stripe and a fake address
 * service: apply → check → approve → sign in from the status link →
 * Stripe Express onboarding → live → employees can spend credits there.
 */
class MerchantJoinOnboardingTest extends TestCase
{
    use FakesDcAddresses;
    use MakesApplications;
    use RefreshDatabase;
    use UsesFakeStripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeDcAddresses();
        $this->fakeStripe();
    }

    public function test_a_shop_goes_from_applying_to_taking_credits(): void
    {
        // 1. Apply, and keep the link shown once.
        $html = $this->post('/apply', [...$this->applicationData(), 'prototype' => '1'])->assertOk()->getContent();
        preg_match('#/apply/status\#(\d+\.[A-Za-z0-9]{40})"#', $html, $m);
        $key = $m[1];
        $application = MerchantApplication::sole();

        // 2–3. The BID admin checks and approves.
        $this->eligibilityReply();
        $this->signIn(Role::BidAdmin);
        $this->post("/bid-admin/applications/{$application->id}/check");
        $this->post("/bid-admin/applications/{$application->id}/approve", ['reason' => 'Independent, inside the district.']);
        $this->assertSame(MerchantApplication::BID_APPROVED, $application->fresh()->status);
        auth()->logout();

        // The shop isn't in any wallet yet.
        $this->assertNotContains('Kettle & Crumb', $this->walletShops());

        // 4. The applicant opens the link and signs in.
        $this->post('/apply/status', ['link' => $key])->assertRedirect('/apply/status');
        $this->get('/apply/status')->assertSee('Step 4 of 5: approved. Set up payouts with Stripe.')->assertSee('Sign in and set up payouts');
        $this->post('/apply/status/sign-in')->assertRedirect('/merchant');

        $user = User::where('email', 'owner@example.com')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(Role::Merchant, $user->role);
        $this->get('/merchant')->assertOk()->assertSee(ApplyController::PROTOTYPE_NOTICE)->assertSee('Not live yet.');

        // Stripe Express onboarding (test mode), the existing flow.
        $this->post('/merchant/stripe/onboard')->assertRedirect();
        $merchant = $application->fresh()->merchant;
        $this->assertNotNull($merchant->stripe_account_id);
        $this->assertNull($merchant->live_at);

        // 5. Stripe says the account can receive transfers: live.
        $this->postWebhook($this->stripeEvent('account.updated', $this->stripe->completeOnboarding($merchant->stripe_account_id)))->assertOk();

        $this->assertSame(MerchantApplication::LIVE, $application->fresh()->status);
        $this->assertNotNull($merchant->fresh()->live_at);
        $this->get('/merchant')->assertDontSee('Not live yet.');
        $this->assertContains('Kettle & Crumb', $this->walletShops());

        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(5, 'clear'));
        $redemption = app(RedemptionService::class)->redeem(Employee::orderBy('id')->firstOrFail(), $merchant->fresh(), 500);
        $this->assertTrue($redemption->isApproved());
    }

    /** @return list<string> */
    private function walletShops(): array
    {
        return \App\Models\Merchant::redeemableFor(Employee::orderBy('id')->firstOrFail())->pluck('name')->all();
    }
}
