<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Public business information only: the committed Merchant Eligibility
 * Matching outputs (the AI's reasoning, Finn's notes, the eval reports)
 * never name an individual or give personal details about one. The AI
 * judges from its own memory and has named owners and chefs before; those
 * were redacted on 2026-10-21 and this test stops them coming back. The
 * frozen prompt is unchanged, so a new eval run that names someone fails
 * here until its output is redacted.
 *
 * The checks are patterns, not a list of names, so no real name ever has
 * to appear in this file.
 */
class MerchantOutputPrivacyTest extends TestCase
{
    /** Words that make a capitalised phrase a company, not a person. */
    private const COMPANY_WORDS = ['Foundation', 'Brands', 'Hospitality', 'Corporation', 'Corp', 'Holding', 'Holdings', 'Group', 'International',
        'Inc', 'LLC', 'Partners', 'Capital', 'Company', 'Co', 'Restaurants', 'Restaurant', 'Enterprises', 'Ventures', 'Management', 'Equity', 'Investments', 'Fund'];

    /** Companies whose names look like a person's. Public business information. */
    private const KNOWN_COMPANIES = ["JDE Peet's"];

    private const NAME = "[A-Z][\\p{L}'’.-]+";

    public function test_committed_merchant_outputs_never_name_an_individual(): void
    {
        $files = [
            config('eligibility.assessments_path'),
            config('eligibility.eval_latest_path'),
            config('eligibility.eval_dir').'/merchant-eval-results.md',
            ...File::glob(config('eligibility.eval_dir').'/merchant-runs/*'),
        ];

        $this->assertGreaterThanOrEqual(5, count($files));
        foreach ($files as $file) {
            $this->assertSame([], self::namedPeople(file_get_contents($file)), basename($file).' names an individual or gives a personal detail. Replace it with "[name removed]" and add a "redacted" note.');
        }
    }

    public function test_evidence_links_in_committed_outputs_carry_no_tracking_ids(): void
    {
        foreach ([config('eligibility.eval_latest_path'), config('eligibility.eval_dir').'/merchant-eval-results.md', ...File::glob(config('eligibility.eval_dir').'/merchant-runs/*')] as $file) {
            $this->assertDoesNotMatchRegularExpression('/[?&](gclid|gbraid|wbraid|srsltid|gad_\w+|utm_\w+)=/', file_get_contents($file), basename($file));
        }
    }

    public function test_every_redaction_carries_a_note(): void
    {
        $assessments = json_decode(file_get_contents(config('eligibility.assessments_path')), true)['assessments'];

        foreach ($assessments as $id => $a) {
            $this->assertTrue(! str_contains($a['reasoning'], '[name removed]') || isset($a['redacted']), "{$id}: every redaction carries a note.");
        }
        $this->assertNotEmpty(array_filter($assessments, fn ($a) => isset($a['redacted'])));
    }

    public function test_the_check_catches_names_and_personal_details(): void
    {
        foreach ([
            'It is owned by restaurateur Pat Example\'s restaurant group.',
            'A single restaurant opened by local chef Sam Sample.',
            'The brand was founded by Alex Placeholder in Austin.',
            'It was started by Jo Fictional\'s Example Group.',
            'A coffee company started by two local Marine veterans.',
            'Owners Pat and Sam Example run it.',
        ] as $text) {
            $this->assertNotSame([], self::namedPeople($text), $text);
        }
    }

    public function test_the_check_allows_companies_places_and_redactions(): void
    {
        foreach ([
            'The pharmacy chain run by Example Healthcare Foundation, headquartered in Los Angeles.',
            'The brand is owned by Example Brands, which is based outside the DC area.',
            'It is owned by Example Hospitality, a restaurant group based in Austin, Texas.',
            'It is owned by restaurateur [name removed]\'s Example Restaurant Group.',
            'A single restaurant opened by local chef [name removed].',
            'It was started by local founders and is headquartered in Washington, DC.',
            'A long-running, family-owned jewelry store near Dupont Circle.',
        ] as $text) {
            $this->assertSame([], self::namedPeople($text), $text);
        }
    }

    /** @return list<string> the phrases that look like a named person or a personal detail */
    private static function namedPeople(string $text): array
    {
        $found = [];
        $name = self::NAME;

        // A role followed by a name: "chef Sam Sample", "owners Pat and Sam Example".
        preg_match_all("/\\b(?i:chefs?|restaurateurs?|co-?founders?|founders?|owners?|proprietors?|entrepreneurs?|ceo|president|husband|wife)\\s+((?:and\\s+)?{$name}(?:\\s+(?:and\\s+)?{$name})?)/u", $text, $m);
        foreach ($m[1] as $phrase) {
            if (! self::isCompany($phrase)) {
                $found[] = $phrase;
            }
        }

        // "founded by Alex Placeholder": two or more capitalised words that aren't a company, or any possessive.
        preg_match_all("/\\b(?:founded|started|opened|owned|run|created|led)\\s+by\\s+(?:(?i:local|the|two|a)\\s+)*({$name}(?:\\s+{$name})+)/u", $text, $m);
        foreach ($m[1] as $phrase) {
            if (! self::isCompany($phrase) || (preg_match("/['’]s\\b\\s+\\S/u", $phrase) && ! in_array($phrase, self::KNOWN_COMPANIES, true))) {
                $found[] = $phrase;
            }
        }

        // Personal details about the people behind a business.
        preg_match_all('/\b(?:Marine|Army|Navy|Air Force|Coast Guard|military)\s+veterans?\b/i', $text, $m);

        return [...$found, ...$m[0]];
    }

    private static function isCompany(string $phrase): bool
    {
        foreach (self::KNOWN_COMPANIES as $company) {
            if (str_starts_with($phrase, $company)) {
                return true;
            }
        }

        return array_intersect(preg_split("/[\\s'’.,]+/u", $phrase), self::COMPANY_WORDS) !== [];
    }
}
