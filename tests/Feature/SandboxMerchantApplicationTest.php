<?php

namespace Tests\Feature;

use App\Http\Controllers\SandboxApplyController;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\MerchantApplication;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\Districts\MerchantApplications;
use App\Services\Geo\Geocoder;
use App\Services\Sandbox\CappedGeocoder;
use App\Services\Sandbox\Sandbox;
use App\Services\Sandbox\SandboxAddressBudget;
use App\Services\Sandbox\SandboxAiBudget;
use App\Services\Sandbox\SandboxRoutes;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Tests\Concerns\FakesDcAddresses;
use Tests\Concerns\UsesFakeStripe;
use Tests\Concerns\UsesSandboxes;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * "A new shop joins" in a sandbox (ADR 0045 addendum): the sandbox's own
 * Apply to join, the BID admin's check (DC address lookup and the AI, both
 * capped), approve (live at once, the last two steps simulated) or decline,
 * then a payment there as Jordan and the sale as that shop. All of it in the
 * sandbox file: no live write, no email, no login for the shop.
 */
class SandboxMerchantApplicationTest extends TestCase
{
    use FakesDcAddresses, RefreshDatabase, UsesFakeStripe, UsesSandboxes;

    private FakeClaudeClient $claude;

    /** @var list<string> */
    private array $mainWrites = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSandboxes();
        $this->fakeStripe();
        $this->fakeDcAddresses();
        Mail::fake();
        Notification::fake();
        // Merchant Eligibility Matching gets an eligibility answer; Redemption Fraud Detection a clear.
        $this->claude = new FakeClaudeClient(fn (string $input) => str_contains($input, 'business_activity')
            ? json_encode(['eligible' => false, 'classification' => 'undetermined', 'confidence' => 0.4,
                'reasoning' => 'I do not know this business.', 'needs_human_review' => true])
            : json_encode(['risk_score' => 10, 'decision' => 'clear', 'explanation' => 'Looks like a normal purchase.']));
        $this->app->instance(ClaudeClient::class, $this->claude);
    }

    private function watchMainConnection(): void
    {
        $main = DB::getDefaultConnection();
        DB::listen(function (QueryExecuted $query) use ($main) {
            if ($query->connectionName === Sandbox::CONNECTION) {
                return;
            }
            if (! preg_match('/^\s*select\b/i', $query->sql)) {
                $this->mainWrites[] = "[{$query->connectionName}] {$query->sql}";
            }
            $this->assertSame($main, $query->connectionName, 'Only the live connection and the sandbox are ever used');
        });
    }

    /** @return array<string, string> */
    private function shop(array $overrides = []): array
    {
        return ['business_name' => 'Kettle & Crumb', 'address' => '1700 K St NW', 'category' => 'cafe',
            'independence_answer' => 'One shop, owned by two neighbors who live in Adams Morgan.', ...$overrides];
    }

    private function applyAsBidAdmin(array $overrides = []): MerchantApplication
    {
        $this->switchRole('bid_admin');
        $this->sandboxPost(route('sandbox.apply.store'), $this->shop($overrides))->assertOk()
            ->assertSee('data-sandbox-application-sent', false)
            ->assertSee('Go as BID admin: Merchant Applications');

        return $this->insideSandbox(fn () => MerchantApplication::orderByDesc('id')->first());
    }

    private function mapRequests(): int
    {
        return count(Http::recorded());
    }

    public function test_a_judge_applies_approves_pays_there_and_sees_the_sale_as_that_shop(): void
    {
        $this->seed();
        $liveApplications = MerchantApplication::count();
        $liveMerchants = Merchant::count();
        $this->watchMainConnection();
        $this->startSandbox();

        // Step 8 opens Merchant Applications as the BID admin, with the sandbox's own Apply button.
        $this->sandboxPost(route('sandbox.role'), ['role' => 'bid_admin', 'step' => SandboxRoutes::JOIN_STEP])
            ->assertRedirect(route('bid-admin.applications'));
        $this->sandboxGet(route('bid-admin.applications'))->assertOk()
            ->assertSee('data-sandbox-apply', false)
            ->assertSee('href="'.route('sandbox.apply').'"', false)
            ->assertDontSee('href="'.route('apply').'"', false);
        $this->sandboxGet(route('sandbox.apply'))->assertOk()->assertSee('Apply as a New Shop')->assertDontSee('Contact email');

        $application = $this->applyAsBidAdmin();
        $this->assertSame('Kettle & Crumb', $application->business_name);
        $this->assertSame(SandboxApplyController::CONTACT_EMAIL, $application->contact_email);
        $this->assertSame(MerchantApplication::APPLIED, $application->status);

        // The check: one DC lookup and one AI call, both counted in this sandbox.
        $this->sandboxPost(route('bid-admin.applications.check', $application->id))
            ->assertRedirect(route('bid-admin.applications.show', $application->id))->assertSessionHas('status');
        $this->assertSame(1, $this->mapRequests());
        $this->assertCount(1, $this->claude->calls);
        $this->assertSame(1, $this->insideSandbox(fn () => app(SandboxAddressBudget::class)->usedInThisSandbox()));
        $this->assertSame(1, $this->insideSandbox(fn () => app(SandboxAiBudget::class)->usedInThisSandbox()));

        // Approve: live at once, with the sentence about the simulated steps.
        $this->sandboxPost(route('bid-admin.applications.approve', $application->id), ['reason' => 'Independent, inside the district.'])
            ->assertRedirect(route('bid-admin.applications.show', $application->id))
            ->assertSessionHas('status', 'Kettle & Crumb approved for the district and live in this sandbox. '.MerchantApplications::SANDBOX_STEPS_SIMULATED);
        $this->sandboxGet(route('bid-admin.applications.show', $application->id))->assertOk()
            ->assertSee(MerchantApplications::SANDBOX_STEPS_SIMULATED)
            ->assertDontSee('href="'.route('district').'"', false);

        [$application, $merchant] = $this->insideSandbox(fn () => [$application->fresh(), Merchant::find($application->fresh()->merchant_id)]);
        $this->assertSame(MerchantApplication::LIVE, $application->status);
        $this->assertNull($application->user_id, 'The shop gets no login of its own in a sandbox');
        $this->assertNull($merchant->user_id);
        $this->assertNotNull($merchant->getRawOriginal('district_approved_at'));
        $this->assertNotNull($merchant->getRawOriginal('live_at'));
        $this->assertNull($merchant->stripe_account_id);
        $this->assertSame(
            [[null, 'applied'], ['applied', 'eligibility_checked'], ['eligibility_checked', 'bid_approved'], ['bid_approved', 'stripe_onboarding'], ['stripe_onboarding', 'live']],
            $this->insideSandbox(fn () => $application->events()->orderBy('id')->get()->map(fn ($e) => [$e->from_status, $e->to_status])->all()));
        $this->assertFalse($this->insideSandbox(fn () => User::where('email', SandboxApplyController::CONTACT_EMAIL)->exists()));

        // Jordan gets credits and pays there.
        $employeeId = $this->insideSandbox(fn () => Employee::whereHas('user', fn ($q) => $q->where('email', SandboxRoutes::email('employee')))->value('id'));
        $this->switchRole('employer');
        $this->sandboxPost(route('employer.allocations.store'), ['mode' => 'individual', 'occasion' => 'recognition', 'amount' => '100', 'employee_id' => $employeeId])
            ->assertSessionHasNoErrors();
        $this->switchRole('employee');
        $this->sandboxGet(route('employee.wallet'))->assertOk()->assertSee('Kettle &amp; Crumb', false);
        $this->sandboxPost(route('employee.redeem'), ['merchant_id' => $merchant->id, 'amount' => '12.34'])->assertSessionHasNoErrors();
        $this->assertSame($merchant->id, $this->insideSandbox(fn () => Redemption::orderByDesc('id')->value('merchant_id')));

        // The Merchant button follows the money to the new shop, and the picker offers it.
        $this->switchRole('merchant')->assertSessionHas('status', "You're now the Merchant (Kettle & Crumb).");
        $this->sandboxGet(route('merchant.dashboard'))->assertOk()->assertSee('$12.34')
            ->assertSee('Change merchant')
            ->assertSee('name="merchant_id" value="'.$merchant->id.'"', false);

        $this->assertSame([], $this->mainWrites, 'A sandbox request wrote to the live database');
        $this->assertSame($liveApplications, MerchantApplication::count());
        $this->assertSame($liveMerchants, Merchant::count());
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_an_address_outside_the_district_goes_through_the_normal_decline(): void
    {
        $this->startSandbox();
        $application = $this->applyAsBidAdmin(['address' => '50 Massachusetts Ave NE']);

        $this->sandboxPost(route('bid-admin.applications.check', $application->id))->assertSessionHas('status');
        $this->assertFalse($this->insideSandbox(fn () => $application->fresh()->inside_district));
        $this->sandboxGet(route('bid-admin.applications.show', $application->id))->assertOk()->assertSee(MerchantApplications::NEEDS_INSIDE);

        $this->sandboxPost(route('bid-admin.applications.approve', $application->id), ['reason' => 'Trying anyway.'])
            ->assertSessionHas('error', MerchantApplications::NEEDS_INSIDE);
        $this->assertSame(MerchantApplication::ELIGIBILITY_CHECKED, $this->insideSandbox(fn () => $application->fresh()->status));

        $this->sandboxPost(route('bid-admin.applications.decline', $application->id), ['reason' => 'Outside the district.'])
            ->assertSessionHas('status');
        $declined = $this->insideSandbox(fn () => $application->fresh());
        $this->assertSame(MerchantApplication::DECLINED, $declined->status);
        $this->assertNull($declined->merchant_id);
    }

    public function test_when_dc_is_down_the_check_says_so_without_asking_the_ai_and_the_bid_admin_can_decline(): void
    {
        $this->startSandbox();
        $application = $this->applyAsBidAdmin(['address' => '1100 Connecticut Ave NW']);
        $this->fakeDcAddresses(down: true);

        $this->sandboxPost(route('bid-admin.applications.check', $application->id))
            ->assertRedirect(route('bid-admin.applications.show', $application->id))
            ->assertSessionHas('error', MerchantApplications::ADDRESS_NOT_CHECKED);
        $this->assertStringStartsWith("The address couldn't be checked right now", MerchantApplications::ADDRESS_NOT_CHECKED);
        $this->assertSame([], $this->claude->calls);
        $this->assertSame(MerchantApplication::APPLIED, $this->insideSandbox(fn () => $application->fresh()->status));

        $this->sandboxPost(route('bid-admin.applications.decline', $application->id), ['reason' => 'Could not check the address.'])
            ->assertSessionHas('status');
        $this->assertSame(MerchantApplication::DECLINED, $this->insideSandbox(fn () => $application->fresh()->status));
    }

    public function test_address_lookups_stop_at_five_per_sandbox_and_fifty_a_day(): void
    {
        $this->assertSame(5, config('sandbox.address_lookups_per_sandbox'));
        $this->assertSame(50, config('sandbox.address_lookups_per_day'));

        $this->startSandbox();
        $application = $this->applyAsBidAdmin(['address' => '1100 Connecticut Ave NW']);
        $this->insideSandbox(function () {
            for ($i = 0; $i < 5; $i++) {
                $this->assertTrue(app(SandboxAddressBudget::class)->take());
            }
        });

        $this->sandboxPost(route('bid-admin.applications.check', $application->id))
            ->assertSessionHas('error', MerchantApplications::ADDRESS_NOT_CHECKED);
        $this->assertSame(0, $this->mapRequests(), 'Over the cap, DC is not asked');
        $this->assertSame([], $this->claude->calls);

        // The day's cap across sandboxes, in its own file (not the AI's).
        $this->startSandbox();
        File::put($this->sandboxDir.'/address-lookups-'.Carbon::now('America/New_York')->toDateString().'.count', '50');
        $application = $this->applyAsBidAdmin(['address' => '1100 Connecticut Ave NW']);
        $this->sandboxPost(route('bid-admin.applications.check', $application->id))
            ->assertSessionHas('error', MerchantApplications::ADDRESS_NOT_CHECKED);
        $this->assertSame(0, $this->mapRequests());
        $this->assertSame(0, $this->insideSandbox(fn () => app(SandboxAiBudget::class)->usedToday()));
    }

    public function test_the_eligibility_check_uses_the_sandbox_ai_budget_and_fails_closed_past_it(): void
    {
        config(['sandbox.ai_calls_per_sandbox' => 0]);
        $this->startSandbox();
        $application = $this->applyAsBidAdmin();

        $this->sandboxPost(route('bid-admin.applications.check', $application->id))->assertSessionHas('status');
        $this->assertSame([], $this->claude->calls, 'Past the cap the AI is not called');
        $checked = $this->insideSandbox(fn () => $application->fresh());
        $this->assertSame(MerchantApplication::ELIGIBILITY_CHECKED, $checked->status);
        $this->assertSame('undetermined', $checked->ai_classification);
        $this->assertTrue($checked->ai_needs_review);
    }

    public function test_the_public_apply_pages_stay_out_of_the_sandbox(): void
    {
        $this->startSandbox();
        foreach (['apply', 'apply.status'] as $name) {
            $this->sandboxGet(route($name))->assertNotFound();
        }
        foreach (['apply.store', 'apply.status.open', 'apply.status.sign-in'] as $name) {
            $this->sandboxPost(route($name), [])->assertNotFound();
        }
        $this->assertFalse(SandboxRoutes::allows('apply.status.sign-in'));
    }

    public function test_outside_a_sandbox_none_of_this_exists(): void
    {
        $this->get('/sandbox/apply')->assertNotFound();
        $this->post('/sandbox/apply', $this->shop())->assertNotFound();

        $this->assertNotInstanceOf(CappedGeocoder::class, app(Geocoder::class));
        $this->expectException(LogicException::class);
        app(MerchantApplications::class)->goLiveInSandbox(new MerchantApplication, new User);
    }

    public function test_the_geocoder_and_claude_client_are_put_back_after_a_sandbox_request(): void
    {
        $before = app(Geocoder::class);
        $this->startSandbox();
        $this->sandboxGet(route('sandbox.home'))->assertOk();

        $this->assertFalse(Sandbox::active());
        $this->assertSame($this->claude, app(ClaudeClient::class));
        $this->assertSame(get_class($before), get_class(app(Geocoder::class)));
    }
}
