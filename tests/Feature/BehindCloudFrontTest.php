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
        $link = session('reviewer_link')['url'];
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

        for ($i = 1; $i <= 5; $i++) {
            $this->viaCloudFront('POST', '/login', "198.51.100.{$i}", $wrong, secret: null)->assertStatus(302);
        }
        $this->viaCloudFront('POST', '/login', '198.51.100.99', $wrong, secret: null)->assertStatus(429);
    }

    public function test_outside_production_the_request_address_is_left_alone(): void
    {
        $this->app['env'] = 'local';

        $this->get(self::BEANSTALK.'/login')->assertSee('action="'.self::BEANSTALK.'/login"', false);
    }
}
