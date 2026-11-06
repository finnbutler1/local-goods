<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Local Goods in one minute" opens the README and the judge home page. The
 * text lives once in partials/one-minute-summary; the README carries a copy,
 * and this keeps the two identical. It was fact-checked against the code on
 * 2026-11-06: it must not imply the Golden Triangle BID endorses or partners
 * with Local Goods.
 */
class OneMinuteSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function summary(): string
    {
        $blade = file_get_contents(resource_path('views/partials/one-minute-summary.blade.php'));

        return $this->squash(strip_tags((string) preg_replace('/\{\{--.*?--\}\}/s', '', $blade)));
    }

    private function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    public function test_it_is_the_first_section_of_the_readme_with_the_same_text(): void
    {
        $readme = file_get_contents(base_path('README.md'));

        preg_match_all('/^## (.+)$/m', $readme, $headings);
        $this->assertSame('Local Goods in one minute', $headings[1][0]);

        preg_match('/^## Local Goods in one minute\n(.*?)^## /ms', $readme, $section);
        $this->assertSame($this->summary(), $this->squash($section[1]));
    }

    public function test_it_opens_the_judge_home_page(): void
    {
        $this->signIn(Role::Judge);

        $this->get('/judge')->assertOk()
            ->assertSeeInOrder(['Welcome,', $this->summary(), 'Thank you for reviewing Local Goods.'], false);
    }

    public function test_it_does_not_claim_a_partnership_or_a_measured_result(): void
    {
        $summary = $this->summary();

        $this->assertDoesNotMatchRegularExpression('/endorse|partner|in partnership|the district can see|benefiting|steady stream|proves/i', $summary);
        $this->assertStringContainsString('working prototype', $summary);
        $this->assertStringContainsString("Stripe's test mode with no real money", $summary);
    }
}
