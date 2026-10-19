<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Services\Ai\ClaudeClient;
use App\Services\Eligibility\MerchantCandidates;
use App\Services\Eligibility\MerchantReviewQueue;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * Every endpoint someone could hammer has a limit: logins, magic links,
 * feedback, adjudication votes, the collusion button and every route that
 * calls Claude. Each limit is checked by sending one request too many.
 */
class RateLimitsTest extends TestCase
{
    use RefreshDatabase;

    /** Sends $limit requests that must get through, then one that must be refused. */
    private function assertLimit(int $limit, callable $send, string $what): void
    {
        for ($i = 1; $i <= $limit; $i++) {
            $this->assertNotSame(429, $send($i)->getStatusCode(), "{$what}: request {$i} of {$limit} was refused.");
        }
        $send($limit + 1)->assertStatus(429);
    }

    public function test_login_allows_five_tries_a_minute(): void
    {
        $user = User::factory()->create();

        $this->assertLimit(5, fn () => $this->post('/login', ['email' => $user->email, 'password' => 'wrong']), 'login');
    }

    public function test_the_other_sign_in_forms_share_one_limit_per_visitor(): void
    {
        $this->assertLimit(FortifyServiceProvider::AUTH_FORMS_PER_MINUTE, fn ($i) => $i % 2
            ? $this->post('/forgot-password', ['email' => 'nobody@example.test'])
            : $this->post('/reset-password', ['token' => 'x', 'email' => 'nobody@example.test']), 'forgot and reset password');
    }

    public function test_password_change_and_confirm_are_limited_per_person(): void
    {
        $this->signIn(Role::EmployerAdmin);
        $this->assertLimit(FortifyServiceProvider::AUTH_FORMS_PER_MINUTE, fn () => $this->put('/user/password', ['current_password' => 'wrong']), 'password change');

        $this->signIn(Role::Merchant);
        $this->assertLimit(FortifyServiceProvider::AUTH_FORMS_PER_MINUTE, fn () => $this->post('/user/confirm-password', ['password' => 'wrong']), 'password confirm');

        // Pages are never limited.
        for ($i = 0; $i < 20; $i++) {
            $this->get('/login')->assertRedirect();
        }
    }

    public function test_magic_links_allow_ten_tries_a_minute(): void
    {
        $this->assertLimit(10, fn ($i) => $this->post('/judge-access', ['link' => "1.wrong-token-{$i}"]), 'magic link');
    }

    public function test_the_magic_link_page_and_form_have_separate_counters(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->get('/judge-access')->assertOk();
        }

        $this->assertNotSame(429, $this->post('/judge-access', ['link' => '1.wrong'])->getStatusCode());
    }

    public function test_feedback_limits_the_public_judge_more_tightly(): void
    {
        $data = fn () => ['page_url' => url('/judge'), 'category' => 'ux_design', 'rating' => 3, 'severity' => 'nice_to_have', 'comment' => 'Note '.Str::random(6)];

        $this->actingAs(User::factory()->role(Role::Judge)->create(['reviewer_type' => ReviewerType::Public]));
        $this->assertLimit(AppServiceProvider::PUBLIC_FEEDBACK_PER_MINUTE, fn () => $this->post('/feedback', $data()), 'public feedback');

        $this->actingAs(User::factory()->role(Role::Judge)->create(['reviewer_type' => ReviewerType::ContestJudge]));
        $this->assertLimit(10, fn () => $this->post('/feedback', $data()), 'named reviewer feedback');
    }

    public function test_adjudication_posts_share_sixty_a_minute(): void
    {
        $this->actingAs(User::factory()->role(Role::Judge)->create(['reviewer_type' => ReviewerType::Bid]));

        $this->assertLimit(60, fn ($i) => $i % 2 ? $this->post('/adjudicate/start', []) : $this->post('/adjudicate/submit'), 'adjudication');

        $vote = Route::getRoutes()->getByName('adjudication.vote');
        $this->assertContains('throttle:60,1,adjudication', $vote->gatherMiddleware());
    }

    public function test_each_redemption_is_one_ai_call_and_limited_per_employee(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->signIn(Role::Employee);

        // Invalid amounts: the limit applies before anything is checked or sent to the AI.
        $this->assertLimit(AppServiceProvider::REDEEM_PER_MINUTE, fn () => $this->post('/employee/redeem', ['merchant_id' => 0, 'amount' => 'x']), 'redeem');
    }

    public function test_the_collusion_button_allows_five_a_minute(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(88, 'hold'));
        $this->signIn(Role::BidAdmin);

        $this->assertLimit(5, fn () => $this->post(route('bid-admin.collusion-example')), 'collusion example');
    }

    public function test_one_busy_button_does_not_lock_out_the_others(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->signIn(Role::BidAdmin);

        $queue = app(MerchantReviewQueue::class);
        $candidate = collect(array_keys(MerchantCandidates::load()))->first(fn ($id) => $queue->assessmentFor($id) !== null);
        for ($i = 0; $i < 8; $i++) {
            $this->post("/bid-admin/merchants/{$candidate}/decisions", [])->assertRedirect();
        }

        // Reset demo (5 a minute) and the collusion button have their own counters.
        $this->assertNotSame(429, $this->post(route('bid-admin.reset'))->getStatusCode());
        $this->assertNotSame(429, $this->post(route('bid-admin.collusion-example'))->getStatusCode());
    }

    /**
     * Any write route whose controller reaches the AI (a redemption, the
     * collusion example, or either named AI function) must be rate-limited.
     * Covers routes added later without touching this file.
     */
    public function test_every_write_route_that_can_call_claude_is_rate_limited(): void
    {
        $aiMarkers = ['ClaudeClient', 'RedemptionService', 'RedemptionFraudDetector', 'CollusionExample', 'MerchantEligibilityMatcher'];
        $found = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $controller = $route->getControllerClass();
            if ($controller === null || $route->methods() === ['GET', 'HEAD'] || ! str_starts_with($controller, 'App\\')) {
                continue;
            }

            $source = file_get_contents((new \ReflectionClass($controller))->getFileName());
            if (! Str::contains($source, $aiMarkers)) {
                continue;
            }

            $found[] = $route->getName();
            $throttles = array_filter($route->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));
            $this->assertNotEmpty($throttles, "{$route->getName()} can call Claude but has no rate limit.");
        }

        $this->assertContains('employee.redeem', $found);
        $this->assertContains('bid-admin.collusion-example', $found);
    }
}
