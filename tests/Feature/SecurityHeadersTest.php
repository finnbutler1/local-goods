<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function assertBaseline(TestResponse $r): void
    {
        $r->assertHeader('X-Content-Type-Options', 'nosniff');
        $r->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $r->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString('camera=()', $r->headers->get('Permissions-Policy'));
        $this->assertStringContainsString('microphone=()', $r->headers->get('Permissions-Policy'));
        $this->assertStringContainsString('geolocation=()', $r->headers->get('Permissions-Policy'));
        $this->assertStringNotContainsString('payment', $r->headers->get('Permissions-Policy'));
        $this->assertNotNull($r->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertNull($r->headers->get('Content-Security-Policy'), 'CSP must stay report-only before the freeze.');
    }

    public function test_public_pages_have_the_headers(): void
    {
        $this->assertBaseline($this->get('/'));
        $this->assertBaseline($this->get('/login'));
    }

    public function test_signed_in_pages_have_the_headers(): void
    {
        $judge = User::factory()->create(['role' => Role::Judge]);
        $this->assertBaseline($this->actingAs($judge)->get('/judge'));
    }

    public function test_csp_is_report_only_and_allows_stripe(): void
    {
        $csp = $this->get('/')->headers->get('Content-Security-Policy-Report-Only');

        foreach (['https://js.stripe.com', 'https://api.stripe.com', 'https://hooks.stripe.com', 'https://checkout.stripe.com'] as $origin) {
            $this->assertStringContainsString($origin, $csp);
        }
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function test_hsts_only_on_https_and_without_preload(): void
    {
        $this->assertNull($this->get('/')->headers->get('Strict-Transport-Security'));

        $hsts = $this->get('https://localhost/')->headers->get('Strict-Transport-Security');
        $this->assertSame('max-age=31536000; includeSubDomains', $hsts);
        $this->assertStringNotContainsString('preload', $hsts);
    }

    public function test_health_check_and_webhook_get_none_of_them(): void
    {
        foreach ([$this->get('/up'), $this->postJson('/stripe/webhook', [])] as $r) {
            foreach (['X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy', 'Permissions-Policy', 'Content-Security-Policy-Report-Only', 'Strict-Transport-Security'] as $h) {
                $this->assertNull($r->headers->get($h), "$h on a path that must stay untouched");
            }
        }
    }
}
