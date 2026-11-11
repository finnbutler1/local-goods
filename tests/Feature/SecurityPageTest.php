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

    public function test_it_states_the_payment_methods_and_the_dispute_gap(): void
    {
        $r = $this->get('/security')->assertOk();

        $r->assertSee('Apple Pay or Google Pay through Stripe Checkout');
        $r->assertSee('Bank debits and other delayed payment methods are deliberately switched off');
        $r->assertSee('payment_intent.succeeded');
        $r->assertSee('app/Services/Payments/StripeCheckout.php');
        $r->assertSee('app/Services/Payments/StripeWebhookHandler.php');
        $r->assertSee("Card disputes and returned payments aren't handled automatically yet (test mode only).");
    }

    public function test_every_cited_file_exists(): void
    {
        foreach (SecurityController::GROUPS as $group) {
            foreach ($group['items'] as [, , $path]) {
                foreach ((array) $path as $file) {
                    $this->assertFileExists(base_path($file), "Cited on /security: $file");
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
