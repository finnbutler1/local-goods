<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Middleware\TrustCloudFront;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Production runs behind CloudFront (ADR 0009). CloudFront calls Beanstalk
 * over plain http, doesn't pass on the Host header, and adds the visitor's IP
 * to X-Forwarded-For. These tests send requests the way they arrive on
 * Beanstalk: http, the Beanstalk host, from a CloudFront edge address.
 */
class BehindCloudFrontTest extends TestCase
{
    use RefreshDatabase;

    private const APP_URL = 'https://d111example.cloudfront.net';

    private const BEANSTALK = 'http://local-goods.example.elasticbeanstalk.com';

    private const EDGE_IP = '130.176.1.10';

    private const SECRET = 'test-origin-secret-not-real';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['env'] = 'production';
        config([
            'app.url' => self::APP_URL,
            'services.cloudfront.origin_secret' => self::SECRET,
            'session.secure' => $this->ebextensionsSetting('SESSION_SECURE_COOKIE') === 'true',
        ]);

        // Laravel skips CSRF checks only in the "testing" environment; they aren't what's tested here.
        $this->withoutMiddleware(PreventRequestForgery::class);

        Route::get('/_test/ip', fn (Request $request) => $request->ip());
    }

    /** A request as CloudFront forwards it: http, Beanstalk host, the origin secret, the viewer's IP appended. */
    private function viaCloudFront(string $method, string $path, string $viewerIp = '203.0.113.7', array $data = [], ?string $forwardedFor = null, ?string $secret = self::SECRET): TestResponse
    {
        $headers = ['X-Forwarded-For' => $forwardedFor ?? $viewerIp];
        if ($secret !== null) {
            $headers[TrustCloudFront::SECRET_HEADER] = $secret;
        }

        $server = $this->transformHeadersToServerVars($headers) + ['REMOTE_ADDR' => self::EDGE_IP];

        return $this->call($method, self::BEANSTALK.$path, $data, server: $server);
    }

    private function ebextensionsSetting(string $name): ?string
    {
        $config = file_get_contents(base_path('.ebextensions/laravel.config'));

        return preg_match('/^\s+'.$name.':\s*"?([^"\n]*)"?\s*$/m', $config, $m) ? $m[1] : null;
    }

    public function test_generated_links_and_redirects_use_app_url(): void
    {
        $page = $this->viaCloudFront('GET', '/login')->assertOk();

        $page->assertSee('action="'.self::APP_URL.'/login"', false);
        $page->assertDontSee('elasticbeanstalk.com', false);
        $this->assertStringStartsWith(self::APP_URL.'/', route('login'));
        $this->assertStringStartsWith(self::APP_URL.'/', url('/employee'));

        // Old /demo URLs redirect to the login page on the CloudFront address.
        $this->viaCloudFront('GET', '/demo')->assertRedirect(self::APP_URL.'/login');
    }

    public function test_redirects_back_after_errors_and_logins_stay_on_app_url(): void
    {
        $user = User::factory()->role(Role::BidAdmin)->create(['password' => 'correct-horse-battery']);

        // A wrong password sends you "back", which comes from the address stored in the session.
        $this->viaCloudFront('GET', '/login');
        $back = $this->viaCloudFront('POST', '/login', data: ['email' => $user->email, 'password' => 'wrong']);
        $back->assertRedirect(self::APP_URL.'/login');

        $in = $this->viaCloudFront('POST', '/login', data: ['email' => $user->email, 'password' => 'correct-horse-battery']);
        $this->assertStringStartsWith(self::APP_URL.'/', $in->headers->get('Location'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_session_cookie_is_secure_in_production(): void
    {
        $this->assertSame('true', $this->ebextensionsSetting('SESSION_SECURE_COOKIE'), '.ebextensions must set SESSION_SECURE_COOKIE: "true"');

        $cookie = $this->viaCloudFront('GET', '/login')->getCookie(config('session.cookie'), decrypt: false);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
    }

    /** The platform admin invites a reviewer through CloudFront; returns the magic link shown to copy. */
    private function magicLink(): string
    {
        $this->signIn(Role::PlatformAdmin);
        $this->viaCloudFront('POST', '/admin/reviewers', data: ['name' => 'Casey Morgan', 'email' => 'casey.judge@example.test', 'reviewer_type' => 'contest_judge'])
            ->assertRedirect(self::APP_URL.'/admin/reviewers');
        $link = session('new_reviewer_link')['url'];
        auth()->logout();

        return $link;
    }

    public function test_magic_links_point_at_app_url_and_work_through_cloudfront(): void
    {
        $link = $this->magicLink();
        $this->assertStringStartsWith(self::APP_URL.'/judge-access#', $link);

        // The browser sends only the path; the part after # goes in the form body.
        $this->viaCloudFront('GET', '/judge-access')->assertOk()
            ->assertSee('action="'.self::APP_URL.'/judge-access"', false);

        $secret = substr($link, strpos($link, '#') + 1);
        foreach (range(1, 2) as $_) {
            $this->viaCloudFront('POST', '/judge-access', data: ['link' => $secret])->assertRedirect(self::APP_URL.'/judge');
            $this->assertAuthenticatedAs(User::where('email', 'casey.judge@example.test')->first());
            auth()->logout();
        }

        $this->viaCloudFront('POST', '/judge-access', data: ['link' => $secret.'x'])->assertStatus(410);
        $this->assertGuest();
    }

    public function test_the_visitor_ip_comes_from_cloudfront_only_with_the_secret(): void
    {
        // Through CloudFront: the right-most entry is the one CloudFront wrote.
        $this->viaCloudFront('GET', '/_test/ip', forwardedFor: '6.6.6.6, 203.0.113.7')->assertSeeText('203.0.113.7');

        // Straight to Beanstalk, with no secret, a wrong one, or a blank one: the header is ignored.
        // (Those are refused outright while the origin lock is on, so switch it off to see this layer.)
        config(['services.cloudfront.enforce' => false]);
        foreach ([null, 'wrong-secret', ''] as $secret) {
            $this->viaCloudFront('GET', '/_test/ip', forwardedFor: '6.6.6.6', secret: $secret)->assertSeeText(self::EDGE_IP);
        }

        // If the secret isn't configured, nothing is trusted, even a request that sends an empty header.
        config(['services.cloudfront.origin_secret' => null]);
        $this->viaCloudFront('GET', '/_test/ip', forwardedFor: '6.6.6.6', secret: '')->assertSeeText(self::EDGE_IP);
    }

    public function test_login_limits_apply_per_visitor_behind_the_same_edge(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);
        $wrong = ['email' => $user->email, 'password' => 'wrong'];

        for ($i = 0; $i < 5; $i++) {
            $this->viaCloudFront('POST', '/login', '203.0.113.7', $wrong)->assertStatus(302);
        }
        $this->viaCloudFront('POST', '/login', '203.0.113.7', $wrong)->assertStatus(429);

        // Writing a different IP in front doesn't help: CloudFront's entry is the one used.
        $this->viaCloudFront('POST', '/login', data: $wrong, forwardedFor: '198.51.100.1, 203.0.113.7')->assertStatus(429);

        // Someone else coming through the same CloudFront edge isn't locked out.
        $this->viaCloudFront('POST', '/login', '198.51.100.20', $wrong)->assertStatus(302);
    }

    public function test_calling_beanstalk_directly_cannot_dodge_the_login_limit(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);
        $wrong = ['email' => $user->email, 'password' => 'wrong'];
        // Even with the origin lock switched off (ORIGIN_VERIFY_ENFORCE=false).
        config(['services.cloudfront.enforce' => false]);

        for ($i = 1; $i <= 5; $i++) {
            $this->viaCloudFront('POST', '/login', "198.51.100.{$i}", $wrong, secret: null)->assertStatus(302);
        }
        $this->viaCloudFront('POST', '/login', '198.51.100.99', $wrong, secret: null)->assertStatus(429);
    }

    public function test_calling_beanstalk_directly_is_refused(): void
    {
        foreach ([null, 'wrong-secret', ''] as $secret) {
            foreach (['/', '/login', '/eval', '/judge-access'] as $path) {
                $response = $this->viaCloudFront('GET', $path, secret: $secret)->assertForbidden();
                $this->assertSame('Forbidden', $response->getContent());
            }
            $this->viaCloudFront('POST', '/login', data: ['email' => 'a@example.test', 'password' => 'x'], secret: $secret)->assertForbidden();
            $this->viaCloudFront('POST', '/stripe/webhook', secret: $secret)->assertForbidden();
        }

        // Through CloudFront, everything works as before.
        $this->viaCloudFront('GET', '/')->assertOk();
        $this->viaCloudFront('GET', '/login')->assertOk();
    }

    public function test_the_health_check_is_never_refused(): void
    {
        $this->viaCloudFront('GET', '/up', secret: null)->assertOk();
        $this->viaCloudFront('GET', '/up')->assertOk();
    }

    public function test_an_empty_configured_secret_refuses_everyone_while_the_lock_is_on(): void
    {
        // The deploy hook refuses this setting (app:check-production-config); if it got through, fail closed.
        config(['services.cloudfront.origin_secret' => '']);

        $this->viaCloudFront('GET', '/login', secret: '')->assertForbidden();
        $this->viaCloudFront('GET', '/login', secret: null)->assertForbidden();
    }

    public function test_the_kill_switch_lets_direct_requests_through(): void
    {
        config(['services.cloudfront.enforce' => false]);

        $this->viaCloudFront('GET', '/login', secret: null)->assertOk();
        $this->viaCloudFront('GET', '/login')->assertOk();
    }

    public function test_the_kill_switch_reads_the_beanstalk_property(): void
    {
        foreach (['false' => false, '0' => false, 'true' => true, null => true] as $value => $enforced) {
            $value === '' ? putenv('ORIGIN_VERIFY_ENFORCE') : putenv("ORIGIN_VERIFY_ENFORCE={$value}");
            $this->assertSame($enforced, (require base_path('config/services.php'))['cloudfront']['enforce'], "ORIGIN_VERIFY_ENFORCE={$value}");
        }
        putenv('ORIGIN_VERIFY_ENFORCE');
    }

    public function test_outside_production_nothing_is_refused(): void
    {
        $this->app['env'] = 'local';

        $this->viaCloudFront('GET', '/login', secret: null)->assertOk();
    }

    public function test_outside_production_the_request_address_is_left_alone(): void
    {
        $this->app['env'] = 'local';

        $this->get(self::BEANSTALK.'/login')->assertSee('action="'.self::BEANSTALK.'/login"', false);
    }
}
