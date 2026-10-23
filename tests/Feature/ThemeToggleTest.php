<?php

namespace Tests\Feature;

use App\Enums\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The header's light/dark toggle (ADR 0025). It is a real button with a
 * pressed state; the server never picks a theme, so without JavaScript the
 * page follows the system setting through CSS.
 */
class ThemeToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_every_layout_has_the_toggle_and_never_sets_a_theme_itself(): void
    {
        $pages = ['public' => $this->get('/'), 'sign-in' => $this->get(route('login')), 'eval' => $this->get(route('eval'))];
        foreach (Role::cases() as $role) {
            $this->signIn($role);
            $pages[$role->value] = $this->get(route($role->dashboardRoute()));
        }

        foreach ($pages as $name => $response) {
            $html = $response->assertOk()->getContent();
            $this->assertMatchesRegularExpression('#<button type="button" data-theme-toggle aria-pressed="false" aria-label="Dark mode"#', $html, $name);
            $this->assertDoesNotMatchRegularExpression('#<html[^>]*data-theme#', $html, "{$name}: the server must not choose a theme");
        }
    }

    public function test_the_saved_choice_is_read_safely_before_the_page_paints(): void
    {
        $head = explode('</head>', $this->get('/')->getContent())[0];

        $this->assertStringContainsString("localStorage.getItem('lg-theme')", $head);
        $this->assertMatchesRegularExpression('#try \{.*localStorage.*\} catch \(e\) \{\}#s', $head, 'Blocked storage must not break the page.');
    }

    public function test_dark_mode_follows_the_system_setting_without_javascript(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString("@media (prefers-color-scheme: dark) {\n    :root:not([data-theme='light']) {", $css);

        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        $built = file_get_contents(public_path('build/'.$manifest['resources/css/app.css']['file']));
        $this->assertStringContainsString('prefers-color-scheme:dark', $built, 'Run npm run build: the built CSS has no dark mode.');
    }

    public function test_the_toggle_script_saves_the_choice_inside_try_catch(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("document.querySelectorAll('[data-theme-toggle]')", $js);
        $this->assertMatchesRegularExpression("#try \{\s*localStorage.setItem\('lg-theme', theme\);\s*\} catch#", $js);
        $this->assertStringContainsString("toggle.setAttribute('aria-pressed'", $js);
    }
}
