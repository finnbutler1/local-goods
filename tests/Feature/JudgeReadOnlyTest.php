<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Http\Middleware\BlockJudgeWrites;
use App\Http\Middleware\KeepPersonalContactsToAdjudication;
use App\Models\CreditPurchase;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\MerchantProduct;
use App\Models\PayCode;
use App\Models\Redemption;
use App\Models\User;
use App\Policies\RedemptionPolicy;
use App\Services\Adjudication\AdjudicationSetBuilder;
use App\Services\Ai\ClaudeClient;
use App\Services\CreditLedger;
use App\Services\PayCodes;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * Judges can look at everything but change nothing except their own
 * feedback. This walks the whole route list, so a write route added later
 * is covered without touching this file.
 */
class JudgeReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    /** Tables that change on any request (sessions, cache) and so aren't data. */
    private const IGNORED_TABLES = ['sessions', 'cache', 'cache_locks', 'migrations'];

    /** Visit bookkeeping on users, which is allowed to change. */
    private const IGNORED_USER_COLUMNS = ['last_seen_at', 'previous_visit_at', 'first_login_at', 'updated_at'];

    private User $judge;

    private PayCode $payCode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        // One held redemption, so Approve and Reject would have something to change.
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(90, 'hold'));
        $this->signIn(Role::Employee);
        $this->post('/employee/redeem', ['merchant_id' => Merchant::firstOrFail()->id, 'amount' => '5']);
        // A live pay code at the demo merchant, so Confirm, Decline and Cancel would have something to change (ADR 0026).
        $this->payCode = app(PayCodes::class)->issue(Employee::orderBy('id')->firstOrFail(),
            Merchant::where('name', config('demo.accounts.merchant.business'))->firstOrFail(), 500);
        $admin = $this->signIn(Role::PlatformAdmin);

        // A feedback item from someone else, for the inbox's update route.
        $admin->feedback()->create([
            'page_url' => url('/admin'), 'category' => 'functionality', 'rating' => 3,
            'severity' => 'should_fix', 'comment' => 'Synthetic.',
        ]);

        // A draft adjudication set, so its edit routes have a case to change.
        app(AdjudicationSetBuilder::class)->newDraft();

        // A pending credit purchase, so "Pay now" has something it could pay.
        app(CreditLedger::class)->requestPurchase(Employer::firstOrFail(), 10000, 'MPG-TEST-1');

        $this->judge = $this->signIn(Role::Judge);
    }

    public function test_every_write_route_refuses_a_judge_and_changes_nothing(): void
    {
        $this->assertEveryWriteRouteRefuses($this->judge, 'a judge');
    }

    /** Personal contacts (ADR 0019) are judges too: the same writes are refused. */
    public function test_every_write_route_refuses_a_personal_contact_and_changes_nothing(): void
    {
        $contact = User::factory()->role(Role::Judge)->create(['reviewer_type' => ReviewerType::PersonalContact]);
        $this->actingAs($contact); // makes their link before the snapshot

        $this->assertEveryWriteRouteRefuses($contact, 'a personal contact');
    }

    private function assertEveryWriteRouteRefuses(User $user, string $who): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->reject(fn (RoutingRoute $r) => in_array($r->getName(), BlockJudgeWrites::ALLOWED_ROUTES, true))
            ->filter(fn (RoutingRoute $r) => array_diff($r->methods(), ['GET', 'HEAD', 'OPTIONS']) !== [])
            // Stripe's webhook has no session, so no judge: see the next test.
            ->reject(fn (RoutingRoute $r) => $r->getName() === 'stripe.webhook');

        $this->assertGreaterThan(5, $routes->count(), 'The route list looks too short.');

        $before = $this->snapshot();

        foreach ($routes as $route) {
            foreach (array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) as $method) {
                $uri = $this->fillParameters($route->uri());
                $this->actingAs($user)
                    ->call($method, $uri, $this->plausibleInput())
                    ->assertStatus(403, "{$method} /{$uri} should be forbidden for {$who}.");
            }
        }

        $this->assertEquals($before, $this->snapshot(), "A request from {$who} changed data.");
    }

    /**
     * Stripe's webhook is the one write route the sweep skips. It sits
     * outside the web group (no session, so nobody is signed in there) and
     * only a valid Stripe signature gets it to do anything. Even a judge's
     * request (or anyone's) without one changes nothing.
     */
    public function test_the_stripe_webhook_has_no_session_and_needs_a_signature(): void
    {
        $webhook = Route::getRoutes()->getByName('stripe.webhook');

        $this->assertNotContains('web', $webhook->gatherMiddleware());
        $this->assertNotContains('stripe.webhook', BlockJudgeWrites::ALLOWED_ROUTES);

        $before = $this->snapshot();
        $this->actingAs($this->judge)->postJson('/stripe/webhook', ['id' => 'evt_x', 'type' => 'payment_intent.succeeded'])->assertStatus(400);
        $this->assertEquals($before, $this->snapshot(), 'An unsigned webhook changed data.');
    }

    public function test_policies_and_gates_refuse_a_judge_on_their_own(): void
    {
        foreach (['reset-demo', 'decide-merchant-eligibility', 'redeem', 'manage-reviewers', 'manage-feedback', 'manage-changelog', 'manage-adjudication', 'adjudicate'] as $ability) {
            $this->assertTrue(Gate::forUser($this->judge)->denies($ability), "Gate {$ability} let a judge through.");
        }

        $this->assertFalse((new RedemptionPolicy)->review($this->judge));
    }

    public function test_the_allowlist_is_short_and_known(): void
    {
        $this->assertSame(
            [
                'judge-access.store', 'logout', 'feedback.store', 'feedback.update',
                'adjudication.start', 'adjudication.vote', 'adjudication.submit',
                'adjudication.more', 'adjudication.finish',
            ],
            BlockJudgeWrites::ALLOWED_ROUTES,
        );
    }

    public function test_the_personal_contact_allowlist_is_short_and_known(): void
    {
        $this->assertSame(
            [
                'adjudication.show', 'adjudication.start', 'adjudication.vote', 'adjudication.submit',
                'adjudication.more', 'adjudication.finish', 'feedback.store', 'feedback.update', 'account', 'dashboard', 'logout',
                'judge-access.show', 'judge-access.store',
            ],
            KeepPersonalContactsToAdjudication::ALLOWED_ROUTES,
        );

        // Every write they may make is also on the judge allowlist.
        $writes = array_filter(KeepPersonalContactsToAdjudication::ALLOWED_ROUTES, fn ($name) => array_diff(Route::getRoutes()->getByName($name)->methods(), ['GET', 'HEAD', 'OPTIONS']) !== []);
        $this->assertSame([], array_values(array_diff($writes, BlockJudgeWrites::ALLOWED_ROUTES)));
    }

    public function test_adjudication_routes_still_refuse_a_judge_who_may_not_vote(): void
    {
        // A plain judge (no reviewer type) and the public and friend types pass
        // the allowlist but not the adjudicate gate.
        $set = app(AdjudicationSetBuilder::class)->newDraft();
        app(AdjudicationSetBuilder::class)->publish($set);
        $case = $set->cases()->firstOrFail();
        $before = $this->snapshot();

        foreach ([null, ReviewerType::Public, ReviewerType::Friend] as $type) {
            $this->judge->forceFill(['reviewer_type' => $type])->save();
            $this->actingAs($this->judge);

            $this->post('/adjudicate/start', ['seen_eval_before' => 'no'])->assertForbidden();
            $this->post("/adjudicate/cases/{$case->id}", ['label' => 'normal', 'reason' => 'Synthetic.'])->assertForbidden();
            $this->post('/adjudicate/submit')->assertForbidden();
        }

        $this->judge->forceFill(['reviewer_type' => null])->save();
        $this->assertEquals($before, $this->snapshot(), 'A refused adjudication request changed data.');
    }

    /** Route parameters filled with ids that exist, so a missed check would really change something. */
    private function fillParameters(string $uri): string
    {
        $ids = [
            'redemption' => Redemption::where('status', Redemption::STATUS_UNDER_REVIEW)->value('id'),
            'purchase' => CreditPurchase::where('status', CreditPurchase::STATUS_PENDING)->value('id'),
            'user' => $this->judge->id,
            'candidate' => 'MC-001',
            // Ids don't restart at 1 on MySQL, so a guess would 404 before the 403.
            'product' => MerchantProduct::value('id'),
            'payCode' => $this->payCode->id,
        ];

        return preg_replace_callback('/\{(\w+)\??\}/', function ($m) use ($ids) {
            $table = (string) str($m[1])->plural()->snake();

            return (string) ($ids[$m[1]] ?? (Schema::hasTable($table) ? DB::table($table)->value('id') : null) ?? 1);
        }, $uri);
    }

    /** Input that a form on that route would accept, so validation isn't what stops the judge. */
    private function plausibleInput(): array
    {
        return [
            'merchant_id' => Merchant::firstOrFail()->id,
            'pay_merchant_id' => Merchant::firstOrFail()->id,
            'pay_code' => $this->payCode->code,
            'amount' => '5',
            'name' => 'Synthetic Reviewer',
            'email' => 'synthetic.reviewer@example.test',
            'reviewer_type' => 'friend',
            'password' => 'a-long-test-password-123',
            'password_confirmation' => 'a-long-test-password-123',
            'current_password' => 'password',
            'title' => 'Synthetic entry',
            'body' => 'Synthetic body.',
            'status' => 'fixed',
            'fixed_commit_sha' => 'abc1234',
            'category' => 'functionality',
            'severity' => 'blocker',
            'rating' => 1,
            'comment' => 'Synthetic.',
            'page_url' => 'http://localhost/judge',
            'decision' => 'approve',
        ];
    }

    /** Every row of every app table. */
    private function snapshot(): array
    {
        $tables = array_diff(
            array_map(fn ($t) => is_array($t) ? $t['name'] : $t, Schema::getTables()),
            self::IGNORED_TABLES,
        );

        return collect($tables)->sort()->values()->mapWithKeys(function (string $table) {
            $rows = DB::table($table)->get()->map(function ($row) use ($table) {
                $row = (array) $row;

                return $table === 'users' ? array_diff_key($row, array_flip(self::IGNORED_USER_COLUMNS)) : $row;
            });

            return [$table => $rows->all()];
        })->all();
    }
}
