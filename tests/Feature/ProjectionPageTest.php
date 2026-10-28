<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Support\Projections\BusinessModel;
use App\Support\Projections\Figures;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public impact projection (ADR 0030): district and merchant numbers
 * only, by scenario and case, always labelled "Projection, not results"
 * with its assumptions listed. No Local Goods fee, revenue or profit.
 */
class ProjectionPageTest extends TestCase
{
    use RefreshDatabase;

    private static function text(string $html): string
    {
        return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));
    }

    public function test_anyone_can_open_it_and_it_is_labelled_a_projection_with_its_assumptions(): void
    {
        $html = $this->get('/projection')->assertOk()->getContent();

        $this->assertStringContainsString('Projection, not results', $html);
        $this->assertStringContainsString('id="assumptions"', $html);
        $this->assertStringContainsString('href="#assumptions"', $html);
        $this->assertStringContainsString('Assumption: to validate in customer discovery', $html);
        $this->assertStringContainsString('aria-current="true"', $html);
    }

    public function test_the_numbers_come_from_the_model_and_follow_the_toggles(): void
    {
        $model = BusinessModel::fromConfig();

        foreach (['a', 'b', 'c'] as $scenario) {
            foreach (['low', 'base', 'high'] as $case) {
                $year4 = $model->run($scenario, $case)->districtImpact()[3];
                $text = self::text($this->get("/projection?scenario={$scenario}&case={$case}")->assertOk()->getContent());

                $this->assertStringContainsString(Figures::dollars($year4['spend']), $text, "{$scenario} {$case}");
                $this->assertStringContainsString(Figures::count($year4['visits']), $text, "{$scenario} {$case}");
                $this->assertStringContainsString(config("projections.scenarios.{$scenario}.name"), $text);
            }
        }

        $this->assertNotSame(
            self::text($this->get('/projection?scenario=a')->getContent()),
            self::text($this->get('/projection?scenario=c')->getContent()),
        );
    }

    public function test_unknown_choices_fall_back_to_golden_triangle_base(): void
    {
        $fallback = $this->get('/projection?scenario=not-a-scenario&case=not-a-case')->assertOk()->getContent();
        $default = $this->get('/projection')->getContent();

        $this->assertSame(self::text($default), self::text($fallback));
        $this->assertStringNotContainsString('not-a-', $fallback);
    }

    public function test_it_shows_no_local_goods_fee_revenue_or_profit(): void
    {
        foreach (['a', 'b', 'c'] as $scenario) {
            foreach (['low', 'base', 'high'] as $case) {
                $text = self::text($this->get("/projection?scenario={$scenario}&case={$case}")->getContent());

                $this->assertDoesNotMatchRegularExpression(PublicClaimsTest::BUSINESS_MODEL_NUMBER, $text);
                $this->assertDoesNotMatchRegularExpression('/\b(EBITDA|gross margin|break-even|platform fee|merchant fee|interchange|payroll)\b/i', $text);
                foreach (BusinessModel::REVENUE_LINES as $label) {
                    $this->assertStringNotContainsString($label, $text);
                }
            }
        }
    }

    public function test_the_outside_estimate_carries_its_label_and_source(): void
    {
        $text = self::text($this->get('/projection')->getContent());

        $this->assertStringContainsString('Estimate from an outside study, not measured by Local Goods', $text);
        $this->assertStringContainsString('Civic Economics', $text);
    }

    public function test_the_landing_page_and_the_bid_impact_page_link_to_it(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->get('/')->assertOk()->assertSee(route('projection'), false);
        $this->actingAs($this->signIn(Role::BidAdmin))->get('/bid-admin/impact')->assertOk()->assertSee(route('projection'), false);
    }
}
