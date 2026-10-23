<?php

namespace Tests\Feature;

use App\Enums\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SweepsPages;
use Tests\TestCase;

/**
 * What public pages and the README say about money and impact. The "roughly
 * 3x" recirculation line is an outside study's figure, so it may appear only
 * with its label and source, never as something Local Goods creates
 * (ADR 0024, replacing ADR 0017's no-3x rule). No business-model numbers.
 * Stripe is described as test mode, neither missing ("a later build") nor live.
 */
class PublicClaimsTest extends TestCase
{
    use RefreshDatabase;
    use SweepsPages;

    private const MULTIPLIER = '/\b3\s?x\b|3×|three times|multiplier/i';

    private const LABEL = 'Estimate from an outside study, not measured by Local Goods';

    private const OVERCLAIM = '/local goods\W+(?:\w+\W+){0,3}?(delivers|creates|produces|generates|achieves|causes)\b[^.]{0,40}(\b3\s?x\b|3×|three times|multiplier)|guarantee/i';

    private const BUSINESS_MODEL_NUMBER = '/(platform fee|merchant fee|employer fee|service fee|transaction fee|interchange|margin|take rate|our revenue|Local Goods(?:\'s|’s)? revenue)[^.]{0,40}(\$\s?\d|\d+(\.\d+)?\s?%)|(\$\s?\d|\d+(\.\d+)?\s?%)[^.]{0,20}(platform fee|interchange|take rate|margin)/i';

    private const STRIPE_MISSING_OR_LIVE = '/later build|not built yet|isn\'t built|coming soon[^.]*stripe|stripe[^.]*coming soon|live mode|live payments|real money moves/i';

    /**
     * The fraud check compares one employee's redemption with their own usual
     * spend ("3x or more the usual amount", and the AI's explanations on
     * /eval). That is a fraud signal, not an economic claim, so it is removed
     * before checking.
     */
    private function withoutFraudSignals(string $text): string
    {
        return preg_replace('/(\b3\s?x|three times|\d+(\.\d+)? times)[^.]{0,40}\b(usual|median)\b/i', '', $text);
    }

    /**
     * Page => visible text, for every page a logged-out visitor or the public
     * judge can open (judges see the BID impact page).
     *
     * @return array<string, string>
     */
    private function publicPages(): array
    {
        $this->seed(DatabaseSeeder::class);
        $pages = [];
        $this->sweepPages(function (string $html, string $where) use (&$pages) {
            $pages[$where] = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));
        }, roles: [Role::Judge]);

        return $pages;
    }

    /** @return array<string, string> "FILE › heading" => section text (## and ###), for README and TRANSPARENCY */
    private function docSections(): array
    {
        $sections = [];
        foreach (['README.md', 'TRANSPARENCY.md'] as $file) {
            foreach (preg_split('/^(?=#{2,3} )/m', file_get_contents(base_path($file))) as $section) {
                $sections[$file.' › '.strtok($section, "\n")] = $section;
            }
        }

        return $sections;
    }

    public function test_every_3x_mention_on_a_page_carries_the_outside_estimate_label_and_source(): void
    {
        $pages = $this->publicPages();
        $this->assertArrayHasKey('/ signed out', $pages);
        $this->assertArrayHasKey('/bid-admin/impact as judge', $pages);

        foreach ($pages as $where => $text) {
            if (preg_match(self::MULTIPLIER, $this->withoutFraudSignals($text))) {
                $this->assertStringContainsString(self::LABEL, $text, $where);
                $this->assertStringContainsString('Civic Economics', $text, $where);
                $this->assertStringContainsString('Indie Impact Study Series', $text, $where);
            }
        }
    }

    public function test_every_3x_mention_in_the_readme_and_transparency_carries_the_label_and_source_in_that_section(): void
    {
        foreach ($this->docSections() as $where => $section) {
            if (preg_match(self::MULTIPLIER, $this->withoutFraudSignals($section))) {
                $this->assertMatchesRegularExpression('/outside study, not measured by Local Goods/i', $section, $where);
                $this->assertStringContainsString('Civic Economics', $section, $where);
            }
        }
    }

    public function test_nothing_says_local_goods_creates_the_effect_or_guarantees_it(): void
    {
        foreach ($this->publicPages() as $where => $text) {
            $this->assertDoesNotMatchRegularExpression(self::OVERCLAIM, $text, $where);
        }
        foreach ($this->docSections() as $where => $section) {
            $this->assertDoesNotMatchRegularExpression(self::OVERCLAIM, $section, $where);
        }
    }

    public function test_no_business_model_numbers_on_public_pages_or_in_the_readme(): void
    {
        foreach ($this->publicPages() as $where => $text) {
            $this->assertDoesNotMatchRegularExpression(self::BUSINESS_MODEL_NUMBER, $text, $where);
        }
        $this->assertDoesNotMatchRegularExpression(self::BUSINESS_MODEL_NUMBER, file_get_contents(base_path('README.md')));
    }

    public function test_no_public_page_says_stripe_is_missing_or_live(): void
    {
        foreach ($this->publicPages() as $where => $text) {
            $this->assertDoesNotMatchRegularExpression(self::STRIPE_MISSING_OR_LIVE, $text, $where);
        }
    }

    public function test_the_readme_labels_the_study_as_an_outside_estimate(): void
    {
        $readme = file_get_contents(base_path('README.md'));

        $this->assertDoesNotMatchRegularExpression('/later build/i', $readme);
        $this->assertStringContainsString('An estimate from an outside study, not measured by Local Goods', $readme);
        foreach (['52.3%', '15.8%', '2011–2012', '134 businesses in 10 U.S. communities, none in DC', 'Civic Economics', 'American Booksellers Association'] as $fact) {
            $this->assertStringContainsString($fact, $readme);
        }
    }

    public function test_roughly_3x_matches_the_configured_study_rates(): void
    {
        $source = config('impact.recirculation');

        $this->assertSame(3.0, round($source['independent_rate'] / $source['chain_rate']));
    }

    public function test_the_rules_are_narrow(): void
    {
        // The fraud signal exception keeps a spending comparison, never an economic claim.
        $this->assertDoesNotMatchRegularExpression(self::MULTIPLIER, $this->withoutFraudSignals('Signal: 3x or more the usual amount. It is about three times the employee\'s usual $13.87 spend.'));
        $this->assertMatchesRegularExpression(self::MULTIPLIER, $this->withoutFraudSignals('Local spending is worth 3x more per dollar.'));
        $this->assertMatchesRegularExpression(self::MULTIPLIER, $this->withoutFraudSignals('a local multiplier'));

        $this->assertMatchesRegularExpression(self::OVERCLAIM, 'Local Goods delivers 3x more local spending.');
        $this->assertMatchesRegularExpression(self::OVERCLAIM, 'Local Goods creates a local multiplier');
        $this->assertMatchesRegularExpression(self::OVERCLAIM, 'Results guaranteed.');
        $this->assertDoesNotMatchRegularExpression(self::OVERCLAIM, 'Money spent at independent local businesses tends to recirculate roughly 3x more. Local Goods has not measured this.');

        $this->assertMatchesRegularExpression(self::BUSINESS_MODEL_NUMBER, 'A platform fee of 3% on each purchase.');
        $this->assertMatchesRegularExpression(self::BUSINESS_MODEL_NUMBER, 'Interchange earns about 1.5%.');
        $this->assertDoesNotMatchRegularExpression(self::BUSINESS_MODEL_NUMBER, 'Independent retailers recirculated 52.3% of their revenue locally.');
    }
}
