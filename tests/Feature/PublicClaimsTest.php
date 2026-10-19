<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SweepsPages;
use Tests\TestCase;

/**
 * What public pages and the README say about money and impact. There is no
 * "3x"/multiplier claim: the one recirculation figure is a labelled outside
 * estimate (ADR 0017). Stripe is described as test mode, neither missing
 * ("a later build") nor live.
 */
class PublicClaimsTest extends TestCase
{
    use RefreshDatabase;
    use SweepsPages;

    private const MULTIPLIER = '/\b3\s?x\b|3×|three times|multiplier/i';

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

    /** @return array<string, string> page => visible text, for every public page */
    private function publicPages(): array
    {
        $this->seed(DatabaseSeeder::class);
        $pages = [];
        $this->sweepPages(function (string $html, string $where) use (&$pages) {
            $pages[$where] = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));
        }, roles: []);

        return $pages;
    }

    public function test_no_public_page_claims_a_3x_multiplier(): void
    {
        $pages = $this->publicPages();
        $this->assertArrayHasKey('/ signed out', $pages);
        $this->assertArrayHasKey('/eval signed out', $pages);

        foreach ($pages as $where => $text) {
            $this->assertDoesNotMatchRegularExpression(self::MULTIPLIER, $this->withoutFraudSignals($text), $where);
        }
    }

    public function test_no_public_page_says_stripe_is_missing_or_live(): void
    {
        foreach ($this->publicPages() as $where => $text) {
            $this->assertDoesNotMatchRegularExpression(self::STRIPE_MISSING_OR_LIVE, $text, $where);
        }
    }

    public function test_the_readme_makes_no_multiplier_claim_and_labels_the_study_as_an_outside_estimate(): void
    {
        $readme = file_get_contents(base_path('README.md'));

        $this->assertDoesNotMatchRegularExpression(self::MULTIPLIER, $this->withoutFraudSignals($readme));
        $this->assertDoesNotMatchRegularExpression('/later build/i', $readme);
        $this->assertStringContainsString('An estimate from an outside study, not measured by Local Goods', $readme);
        foreach (['52.3%', '15.8%', '2011–2012', '134 businesses in 10 U.S. communities, none in DC', 'Civic Economics', 'American Booksellers Association'] as $fact) {
            $this->assertStringContainsString($fact, $readme);
        }
    }

    public function test_the_fraud_signal_exception_is_narrow(): void
    {
        // The exception keeps a spending comparison, never an economic claim.
        $this->assertDoesNotMatchRegularExpression(self::MULTIPLIER, $this->withoutFraudSignals('Signal: 3x or more the usual amount. It is about three times the employee\'s usual $13.87 spend.'));
        $this->assertMatchesRegularExpression(self::MULTIPLIER, $this->withoutFraudSignals('Local spending is worth 3x more per dollar.'));
        $this->assertMatchesRegularExpression(self::MULTIPLIER, $this->withoutFraudSignals('a local multiplier'));
    }
}
