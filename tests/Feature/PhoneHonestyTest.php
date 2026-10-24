<?php

namespace Tests\Feature;

use App\Enums\Role;
use Database\Seeders\DatabaseSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nothing honest is hidden on phones to save space: each line below must
 * appear at least once outside anything a phone hides (hidden, hidden
 * until a breakpoint, max-*:hidden, sr-only).
 */
class PhoneHonestyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function assertVisibleOnPhones(string $html, string $text, string $where): void
    {
        $doc = new DOMDocument;
        @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query('//text()[contains(normalize-space(.), "'.$text.'")]');

        $this->assertGreaterThan(0, $nodes->length, "\"{$text}\" is missing on {$where}.");
        foreach ($nodes as $node) {
            if (! $this->hiddenOnPhones($node->parentNode)) {
                return;
            }
        }
        $this->fail("\"{$text}\" is hidden on phones on {$where}.");
    }

    private function hiddenOnPhones(?\DOMNode $node): bool
    {
        for (; $node instanceof DOMElement; $node = $node->parentNode) {
            $classes = preg_split('/\s+/', $node->getAttribute('class'));
            if ($node->hasAttribute('hidden') || array_intersect($classes, ['hidden', 'sr-only', 'max-sm:hidden', 'max-md:hidden', 'max-lg:hidden'])) {
                return true;
            }
        }

        return false;
    }

    public function test_the_landing_page_keeps_its_demo_and_affiliation_lines_and_the_evidence_label(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertVisibleOnPhones($html, 'Demo data: all people and transactions are fictional.', '/');
        $this->assertVisibleOnPhones($html, 'Not affiliated with the Golden Triangle BID.', '/');
        $this->assertVisibleOnPhones($html, 'All businesses, people, and transactions are fictional.', '/');
        $this->assertVisibleOnPhones($html, 'Estimate from an outside study, not measured by Local Goods', '/');
        $this->assertVisibleOnPhones($html, 'Source:', '/');
    }

    public function test_every_signed_in_page_says_it_is_a_demonstration_on_phones(): void
    {
        foreach ([Role::Employee, Role::Merchant, Role::EmployerAdmin, Role::BidAdmin, Role::PlatformAdmin, Role::Judge] as $role) {
            $user = $this->signIn($role);
            $page = route($role->dashboardRoute());
            $html = $this->actingAs($user)->get($page)->assertOk()->getContent();

            $this->assertVisibleOnPhones($html, 'All businesses, people and transactions are fictional.', "{$page} as {$role->value}");
        }
    }

    public function test_impact_wallet_money_mode_and_view_as_lines_stay_visible(): void
    {
        $bid = $this->actingAs($this->signIn(Role::BidAdmin));
        $impact = $bid->get('/bid-admin/impact')->assertOk()->getContent();
        $this->assertVisibleOnPhones($impact, 'Demo data: all people and transactions are fictional.', '/bid-admin/impact');
        $this->assertVisibleOnPhones($impact, 'Estimate from an outside study, not measured by Local Goods', '/bid-admin/impact');

        $participation = $bid->get('/bid-admin/participation')->assertOk()->getContent();
        // The column name is the label on each phone card, not only in the (visually hidden) header row.
        $this->assertStringContainsString('data-label="Owed via Stripe test mode"', $participation);

        $wallet = $this->actingAs($this->signIn(Role::Employee))->get('/employee')->assertOk()->getContent();
        $this->assertVisibleOnPhones($wallet, 'Demo account', '/employee');
        $this->assertVisibleOnPhones($wallet, 'Simulated', '/employee');

        $judge = $this->signIn(Role::Judge);
        $viewAs = $this->actingAs($judge)->followingRedirects()->get(route('judge.view-as', 'employer_admin'))->assertOk()->getContent();
        $this->assertVisibleOnPhones($viewAs, 'This is read-only.', 'view as employer admin');
    }
}
