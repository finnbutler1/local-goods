<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Support\EmployerPlatformFee;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SweepsPages;
use Tests\TestCase;

/**
 * What public pages and the README say about money and impact. The "roughly
 * 3x" recirculation line is an outside study's figure, so it may appear only
 * with its label and source, never as something Local Goods creates
 * (ADR 0024, replacing ADR 0017's no-3x rule). No business-model numbers,
 * except on the signed-in Business model page and in the README's
 * "Business model" section, and only next to "Projection, not results" and
 * a link to the assumptions (ADR 0030). Stripe is described as test mode,
 * neither missing ("a later build") nor live.
 *
 * Runs with the employer platform fee off (the default) and, through
 * PublicClaimsEmployerFeeOnTest, with it on (ADR 0042).
 */
class PublicClaimsTest extends TestCase
{
    use RefreshDatabase;
    use SweepsPages;

    private const MULTIPLIER = '/\b3\s?x\b|3×|three times|multiplier/i';

    private const LABEL = 'Estimate from an outside study, not measured by Local Goods';

    private const PROJECTION_LABEL = 'Projection, not results';

    /** The only page that may show fee, revenue and profit figures, and only to signed-in reviewers and admins. */
    private const BUSINESS_MODEL_PAGE = '/business-model as judge';

    /** @var array<string, string> page => raw HTML, filled by publicPages() */
    private array $html = [];

    /**
     * The one element outside the business model page that may show a fee
     * figure: the employer dashboard's planned platform fee box while the fee
     * is switched off (ADR 0042), and only while it carries the projection
     * label. Removed before the check; test_the_planned_fee_preview_* says
     * where it may appear and what it must carry.
     */
    private const PLANNED_FEE_PREVIEW = '/<aside\b[^>]*\bdata-planned-fee-preview\b[^>]*>.*?<\/aside>/s';

    private const PLANNED_FEE_PAGE = '/employer as judge';

    /** @var array<string, list<string>> page => planned-fee preview elements removed by publicPages() */
    private array $previews = [];

    /**
     * The other fee figures allowed: while the employer platform fee is
     * switched on (ADR 0042), the employer dashboard's own fee lines (the Buy
     * credits form, a purchase's breakdown and its row), each marked
     * data-employer-fee-live and only while it says which money mode it is
     * in ("Stripe test mode: no real money", "Simulated: no real money").
     */
    private const LIVE_FEE = '/<p\b[^>]*\bdata-employer-fee-live\b[^>]*>.*?<\/p>/s';

    private const LIVE_FEE_LABEL = 'no real money';

    /** @var array<string, list<string>> page => live fee elements removed by publicPages() */
    private array $liveFees = [];

    private const OVERCLAIM = '/local goods\W+(?:\w+\W+){0,3}?(delivers|creates|produces|generates|achieves|causes)\b[^.]{0,40}(\b3\s?x\b|3×|three times|multiplier)|guarantee/i';

    /**
     * A fee, revenue, profit, EBITDA, interchange, margin or take-rate word
     * within a sentence of a dollar amount or a percentage. Words must stand
     * alone (not inside an ID like 3fee0b1c or a word like "marginal"), and
     * so must the number. "Their revenue" is the outside study's merchants
     * (52.3% of their revenue recirculated), not Local Goods'.
     */
    public const BUSINESS_MODEL_NUMBER = '/(?<![a-z0-9])(fees?|platform fees?|merchant fees?|employer fees?|service fees?|transaction fees?|interchange|margins?|take rates?|(?<!their )revenues?|profits?|EBITDA|Local Goods(?:\'s|’s)? revenue)(?![a-z0-9])[^.]{0,40}(\$\s?\d|(?<![a-z0-9.])\d+(\.\d+)?\s?%)|(\$\s?\d|(?<![a-z0-9.])\d+(\.\d+)?\s?%)[^.]{0,20}(?<![a-z0-9])(fees?|interchange|take rates?|margins?|(?<!their )revenues?|profits?|EBITDA)(?![a-z0-9])/i';

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
            [$checked, $this->previews[$where]] = $this->withoutLabelled(self::PLANNED_FEE_PREVIEW, self::PROJECTION_LABEL, $html);
            [$checked, $this->liveFees[$where]] = $this->withoutLabelled(self::LIVE_FEE, self::LIVE_FEE_LABEL, $checked);
            $pages[$where] = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($checked)));
            $this->html[$where] = $html;
        }, roles: [Role::Judge]);

        return $pages;
    }

    /**
     * The HTML without the elements matching $element that carry $label, and
     * the elements removed. An element without the label stays and is checked.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function withoutLabelled(string $element, string $label, string $html): array
    {
        $removed = [];
        $checked = preg_replace_callback($element, function ($m) use ($label, &$removed) {
            if (! str_contains($m[0], $label)) {
                return $m[0];
            }
            $removed[] = $m[0];

            return '';
        }, $html);

        return [$checked, $removed];
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
        $this->assertMatchesRegularExpression(self::MULTIPLIER, $pages['/ signed out'], 'The landing page carries the labelled line, so this check is not empty.');

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

        $this->assertMatchesRegularExpression(self::MULTIPLIER, $this->docSections()['README.md › ## Why it matters'], 'The README carries the labelled line, so this check is not empty.');
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

    public function test_no_business_model_numbers_on_public_pages(): void
    {
        $pages = $this->publicPages();
        $this->assertArrayNotHasKey('/business-model signed out', $pages, 'Logged-out visitors never see the business model.');
        $this->assertArrayHasKey(self::BUSINESS_MODEL_PAGE, $pages, 'Reviewers can open the business model, so the exception below is checked.');

        foreach ($pages as $where => $text) {
            if ($where === self::BUSINESS_MODEL_PAGE) {
                $this->assertMatchesRegularExpression(self::BUSINESS_MODEL_NUMBER, $text, 'The business model page shows figures, so its label check is not empty.');
                $this->assertStringContainsString(self::PROJECTION_LABEL, $text, $where);
                $this->assertStringContainsString('href="#assumptions"', $this->html[$where], $where);
                $this->assertStringContainsString('id="assumptions"', $this->html[$where], $where);

                continue;
            }
            $this->assertDoesNotMatchRegularExpression(self::BUSINESS_MODEL_NUMBER, $text, $where);
        }
    }

    public function test_the_planned_fee_preview_is_only_on_the_employer_dashboard_with_its_label_and_link(): void
    {
        $this->publicPages();

        if (EmployerPlatformFee::charges()) {
            $this->assertSame([], array_keys(array_filter($this->previews)), 'With the fee on there is no planned-fee preview.');

            return;
        }

        $this->assertSame([self::PLANNED_FEE_PAGE], array_keys(array_filter($this->previews)), 'Only the employer dashboard has the planned fee preview.');
        $this->assertCount(1, $this->previews[self::PLANNED_FEE_PAGE]);
        $preview = $this->previews[self::PLANNED_FEE_PAGE][0];
        $this->assertStringContainsString('Planned platform fee (not charged during the pilot)', $preview);
        $this->assertStringContainsString(self::PROJECTION_LABEL, $preview);
        $this->assertStringContainsString(route('business-model').'#assumptions', $preview, 'Judges can open the business model, so they get the link.');
        $this->assertMatchesRegularExpression('/\$\d/', $preview, 'The exception is not empty: the preview shows the planned price.');
    }

    public function test_live_fee_figures_only_on_the_employer_dashboard_and_only_while_the_fee_is_on(): void
    {
        $this->publicPages();
        $pages = array_keys(array_filter($this->liveFees));

        if (! EmployerPlatformFee::charges()) {
            $this->assertSame([], $pages, 'With the fee off no page shows a charged fee.');

            return;
        }

        $this->assertSame([self::PLANNED_FEE_PAGE], $pages, 'Only the employer dashboard shows the employer platform fee charged.');
        foreach ($this->liveFees[self::PLANNED_FEE_PAGE] as $element) {
            $this->assertMatchesRegularExpression('/(Stripe test mode|Simulated|simulated): no real money/', $element);
        }
        $this->assertTrue(collect($this->liveFees[self::PLANNED_FEE_PAGE])->contains(fn ($el) => str_contains($el, 'data-employer-fee-next')), 'The Buy credits form says the fee is on.');
    }

    public function test_business_model_numbers_in_the_readme_only_in_its_labelled_business_model_section(): void
    {
        foreach ($this->docSections() as $where => $section) {
            if (! str_starts_with($where, 'README.md')) {
                continue;
            }
            if ($where === 'README.md › ## Business model') {
                $this->assertStringContainsString(self::PROJECTION_LABEL, $section, $where);
                $this->assertStringContainsString('docs/finance/MODEL.md', $section, $where);

                continue;
            }
            $this->assertDoesNotMatchRegularExpression(self::BUSINESS_MODEL_NUMBER, $section, $where);
        }
    }

    public function test_the_old_what_local_goods_is_heading_is_gone(): void
    {
        foreach ($this->publicPages() as $where => $text) {
            $this->assertStringNotContainsStringIgnoringCase('What Local Goods is', $text, $where);
        }
        $this->assertStringNotContainsStringIgnoringCase('What Local Goods is', file_get_contents(base_path('README.md')));
    }

    public function test_no_public_page_says_stripe_is_missing_or_live(): void
    {
        foreach ($this->publicPages() as $where => $text) {
            $this->assertDoesNotMatchRegularExpression(self::STRIPE_MISSING_OR_LIVE, $text, $where);
        }
    }

    public function test_the_readme_leads_with_the_mission_and_labels_the_study_as_an_outside_estimate(): void
    {
        $readme = file_get_contents(base_path('README.md'));

        $this->assertDoesNotMatchRegularExpression('/later build/i', $readme);
        $this->assertStringContainsString('An estimate from an outside study, not measured by Local Goods', $readme);
        $this->assertStringContainsString('## You work here. Spend here.', $readme);
        $this->assertStringContainsString('Real results here will be measured in a pilot.', $readme);
        $this->assertStringContainsString('people decide every held redemption and every new merchant', $readme);
        $this->assertStringNotContainsString('do not make them', $readme);
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
        $this->assertMatchesRegularExpression(self::BUSINESS_MODEL_NUMBER, 'Fees: $5 per employee.');
        $this->assertMatchesRegularExpression(self::BUSINESS_MODEL_NUMBER, 'We keep 2% as margin.');
        // A random-looking ID that happens to contain "fee", next to a real amount, is not a fee claim.
        $this->assertDoesNotMatchRegularExpression(self::BUSINESS_MODEL_NUMBER, 'Purchase 3fee0b1c-0fee-4a2b-8c3d-9e8f7a6b5fee paid $250.00.');
        $this->assertDoesNotMatchRegularExpression(self::BUSINESS_MODEL_NUMBER, 'Token cs_test_a1fee9 for 15% of the balance.');
        $this->assertDoesNotMatchRegularExpression(self::BUSINESS_MODEL_NUMBER, 'A marginal note about $5 lunches.');
        $this->assertMatchesRegularExpression(self::BUSINESS_MODEL_NUMBER, 'Revenue $28k in year 1.');
        $this->assertMatchesRegularExpression(self::BUSINESS_MODEL_NUMBER, 'EBITDA −$198k');
        $this->assertMatchesRegularExpression(self::BUSINESS_MODEL_NUMBER, 'A profit of $4.02M.');
        $this->assertMatchesRegularExpression(self::BUSINESS_MODEL_NUMBER, 'Our revenue is $1.2M.');
        $this->assertDoesNotMatchRegularExpression(self::BUSINESS_MODEL_NUMBER, 'Independent retailers recirculated 52.3% of their revenue locally, against 15.8% for chains.');

        // The planned-fee exception removes only its own labelled element: an unlabelled one, or fee text beside it, is still checked.
        $labelled = '<aside data-planned-fee-preview><p>'.self::PROJECTION_LABEL.'</p><p>Platform fee $6.00 per employee.</p></aside>';
        $unlabelled = '<aside data-planned-fee-preview><p>Platform fee $6.00 per employee.</p></aside>';
        $strip = fn (string $html) => preg_replace_callback(self::PLANNED_FEE_PREVIEW, fn ($m) => str_contains($m[0], self::PROJECTION_LABEL) ? '' : $m[0], $html);
        $this->assertDoesNotMatchRegularExpression(self::BUSINESS_MODEL_NUMBER, strip_tags($strip($labelled)));
        $this->assertMatchesRegularExpression(self::BUSINESS_MODEL_NUMBER, strip_tags($strip($unlabelled)));
        $this->assertMatchesRegularExpression(self::BUSINESS_MODEL_NUMBER, strip_tags($strip($labelled.'<p>A platform fee of $6.00.</p>')));

        // So does the live-fee exception: only a marked element that says "no real money".
        $live = fn (string $html) => strip_tags($this->withoutLabelled(self::LIVE_FEE, self::LIVE_FEE_LABEL, $html)[0]);
        $this->assertDoesNotMatchRegularExpression(self::BUSINESS_MODEL_NUMBER, $live('<p data-employer-fee-live>Platform fee $240.00 · Stripe test mode: no real money</p>'));
        $this->assertMatchesRegularExpression(self::BUSINESS_MODEL_NUMBER, $live('<p data-employer-fee-live>Platform fee $240.00</p>'));
        $this->assertMatchesRegularExpression(self::BUSINESS_MODEL_NUMBER, $live('<p>Platform fee $240.00 · Stripe test mode: no real money</p>'));
        $this->assertMatchesRegularExpression(self::BUSINESS_MODEL_NUMBER, $live('<p data-employer-fee-live>no real money</p><p>A platform fee of $6.00.</p>'));
    }
}
