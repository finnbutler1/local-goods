<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Controllers\SecurityController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_is_public_and_has_every_group_and_the_not_done_list(): void
    {
        $r = $this->get('/security')->assertOk()->assertSee('Security &amp; Trust', false);

        foreach (['Money', 'Access', 'Data', 'AI', 'Infrastructure'] as $title) {
            $r->assertSee($title);
        }
        $r->assertSee("What's not done yet", false);
        $r->assertSee('No independent security review or penetration test.');
        $r->assertSee('No two-factor sign-in.');
        $r->assertSee('SPF and DMARC');
    }

    public function test_every_cited_file_exists(): void
    {
        foreach (SecurityController::GROUPS as $group) {
            foreach ($group['items'] as [, , $path]) {
                if ($path !== null) {
                    $this->assertFileExists(base_path($path), "Cited on /security: $path");
                }
            }
        }
    }

    public function test_no_guarantees_and_no_figures(): void
    {
        $text = strip_tags($this->get('/security')->getContent());

        $this->assertDoesNotMatchRegularExpression('/\b(unhackable|bank-grade|military-grade|impenetrable|guarantee[ds]?|100% secure|completely secure)\b/i', $text);
        $this->assertDoesNotMatchRegularExpression('/\$\s?\d|\d\s?%/', $text);
        $this->assertDoesNotMatchRegularExpression('/\bfees?\b/i', $text);
    }

    public function test_signed_in_people_can_open_it_too(): void
    {
        $judge = User::factory()->create(['role' => Role::Judge]);
        $this->actingAs($judge)->get('/security')->assertOk();
    }

    public function test_footer_links_to_it_from_the_public_pages(): void
    {
        foreach (['/', '/security', '/who-does-what'] as $page) {
            $this->get($page)->assertSee('href="'.route('security').'"', false)->assertSee('Security &amp; Trust', false);
        }
    }
}
