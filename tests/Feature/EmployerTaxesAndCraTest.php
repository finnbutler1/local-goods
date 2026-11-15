<?php

namespace Tests\Feature;

use App\Enums\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Taxes section and the folded "For banks: the Community Reinvestment
 * Act" note: on the employer dashboard (live and as a judge) and on
 * /business-model. General information only: no fee figures, no rates, never
 * a claim that Local Goods qualifies or that the district is low- or
 * moderate-income.
 */
class EmployerTaxesAndCraTest extends TestCase
{
    use RefreshDatabase;

    private const CRA_SUMMARY = 'For banks: the Community Reinvestment Act';

    private const CRA_OFFICER = 'Check with your CRA officer.';

    private const NO_ADVICE = "Local Goods doesn't give tax advice; check with your payroll provider or tax advisor.";

    private function element(string $html, string $pattern): string
    {
        $this->assertSame(1, preg_match($pattern, $html, $m), $pattern);

        return html_entity_decode(strip_tags($m[0]), ENT_QUOTES);
    }

    public function test_the_employer_dashboard_has_taxes_then_the_folded_cra_note_with_no_fee_figures(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach ([Role::EmployerAdmin, Role::Judge] as $role) {
            $html = $this->actingAs($this->signIn($role))->get('/employer')->assertOk()->getContent();

            $taxes = $this->element($html, '/<ul\b[^>]*data-employer-taxes[^>]*>.*?<\/ul>/s');
            $this->assertStringContainsString(self::NO_ADVICE, $taxes);
            $this->assertStringContainsString('href="https://www.irs.gov/publications/p15b"', $html);
            $this->assertStringContainsString('href="#payroll"', $html);

            $details = $this->element($html, '/<details\b[^>]*data-cra-details[^>]*>.*?<\/details>/s');
            $this->assertMatchesRegularExpression('/<details\b(?![^>]*\bopen\b)[^>]*data-cra-details/', $html, 'Collapsed by default');
            $this->assertStringContainsString(self::CRA_SUMMARY, $details);
            $this->assertStringContainsString(self::CRA_OFFICER, $details);
            $this->assertStringContainsString('https://www.federalregister.gov/documents/2026/08/12/2026-16454/community-reinvestment-act-regulations', $html);
            $this->assertLessThan(strpos($html, 'data-cra-details'), strpos($html, 'data-employer-taxes'), 'After the Taxes section');
            preg_match('/<section\b[^>]*id="payroll".*?<\/details>/s', $html, $payroll);
            $this->assertStringNotContainsString(route('business-model'), $payroll[0], 'Employers cannot open /business-model: no link from these sections');
            if ($role === Role::EmployerAdmin) {
                $this->assertStringNotContainsString(route('business-model'), $html);
            }

            foreach ([$taxes, $details] as $text) {
                $this->assertDoesNotMatchRegularExpression('/\$\s?\d|\d\s?%/', $text, 'No money figures or rates');
                $this->assertDoesNotMatchRegularExpression(PublicClaimsTest::BUSINESS_MODEL_NUMBER, $text);
                $this->assertDoesNotMatchRegularExpression('/\bfees?\b|guarantee|qualifies|Golden Triangle/i', $text);
            }
        }
    }

    public function test_business_model_has_both_sections(): void
    {
        $this->seed(DatabaseSeeder::class);
        $html = $this->actingAs($this->signIn(Role::Judge))->get(route('business-model'))->assertOk()->getContent();

        $this->assertStringContainsString('Taxes, for employers', $html);
        $this->assertStringContainsString(self::NO_ADVICE, html_entity_decode($html, ENT_QUOTES));
        $this->assertStringContainsString(self::CRA_SUMMARY, $html);
        $this->assertStringContainsString(self::CRA_OFFICER, $html);
    }
}
