<?php

namespace Tests\Feature;

use App\Enums\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Transit lines brand (ADR 0025) is on every layout: Logo A in the
 * headers and footer, the icons and web app manifest in the head, and the
 * footer tagline "Reroute your lunch.".
 */
class BrandLayoutTest extends TestCase
{
    use RefreshDatabase;

    /** Logo A's outlined wordmark sets this viewBox; nothing else on the site does. */
    private const LOGO = 'viewBox="0 0 290.401 64"';

    private const ICON_LINKS = [
        '<link rel="icon" href="/favicon.ico" sizes="48x48">',
        '<link rel="icon" href="/favicon.svg" type="image/svg+xml">',
        '<link rel="icon" href="/favicon-32.png" type="image/png" sizes="32x32">',
        '<link rel="icon" href="/favicon-16.png" type="image/png" sizes="16x16">',
        '<link rel="apple-touch-icon" href="/apple-touch-icon.png">',
        '<link rel="manifest" href="/site.webmanifest">',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_public_layout_has_the_logo_in_the_header_and_footer(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        [$header, $rest] = explode('</header>', $html, 2);
        $this->assertStringContainsString('aria-label="Local Goods home"', $header);
        $this->assertStringContainsString(self::LOGO, $header);
        $footer = substr($rest, strrpos($rest, '<footer'));
        $this->assertStringContainsString(self::LOGO, $footer);
        $this->assertStringContainsString('var(--color-night-primary)', $footer, 'The footer is a night panel, so it uses the on-dark logo.');
        $this->assertStringContainsString('Reroute your lunch.', $footer);
        $this->assertStringContainsString('Not affiliated with the Golden Triangle BID.', $footer);
    }

    public function test_signed_in_and_sign_in_pages_show_the_logo_as_a_named_home_link(): void
    {
        $this->assertLogoLink($this->get(route('login'))->assertOk()->getContent());

        foreach (Role::cases() as $role) {
            $this->signIn($role);
            $html = $this->get(route($role->dashboardRoute()))->assertOk()->getContent();
            $this->assertLogoLink($html);
            $this->assertStringContainsString('Reroute your lunch.', $html, $role->value);
        }
    }

    public function test_every_layout_links_the_icons_and_manifest(): void
    {
        $pages = ['/' => $this->get('/'), 'login' => $this->get(route('login')), 'error' => $this->get('/no-such-page')];
        $this->signIn(Role::Employee);
        $pages['wallet'] = $this->get(route('employee.wallet'));

        foreach ($pages as $name => $response) {
            foreach (self::ICON_LINKS as $link) {
                $this->assertStringContainsString($link, $response->getContent(), "{$name}: {$link}");
            }
        }
    }

    public function test_the_icon_files_and_manifest_exist(): void
    {
        foreach (['favicon.ico', 'favicon.svg', 'favicon-16.png', 'favicon-32.png', 'apple-touch-icon.png', 'icon-192.png', 'icon-512.png'] as $file) {
            $this->assertFileExists(public_path($file));
        }
        foreach (['favicon-16.png' => 16, 'favicon-32.png' => 32, 'apple-touch-icon.png' => 180, 'icon-192.png' => 192, 'icon-512.png' => 512] as $file => $size) {
            $this->assertSame([$size, $size], array_slice(getimagesize(public_path($file)), 0, 2), $file);
        }

        $manifest = json_decode(file_get_contents(public_path('site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Local Goods', $manifest['name']);
        $this->assertNotEmpty($manifest['icons']);
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }
    }

    public function test_the_old_tagline_is_gone(): void
    {
        $this->assertStringNotContainsString('Spend local. Prove it.', $this->get('/')->getContent());
        $this->signIn(Role::BidAdmin);
        $this->assertStringNotContainsString('Spend local. Prove it.', $this->get(route('bid-admin.dashboard'))->getContent());
    }

    private function assertLogoLink(string $html): void
    {
        $this->assertMatchesRegularExpression('#<a href="[^"]*" class="[^"]*" aria-label="Local Goods home">\s*<svg '.preg_quote(self::LOGO, '#').' aria-hidden="true"#', $html);
    }
}
