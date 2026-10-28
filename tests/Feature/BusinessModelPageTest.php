<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Http\Controllers\Projections\BusinessModelController;
use App\Models\User;
use App\Support\Projections\BusinessModel;
use App\Support\Projections\Figures;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Business model page and its CSV (ADR 0030): signed-in reviewers, BID
 * admin and platform admin only, every screen labelled "Projection, not
 * results", every figure from the calculator.
 */
class BusinessModelPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private static function text(string $html): string
    {
        return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));
    }

    public function test_logged_out_visitors_are_sent_to_log_in(): void
    {
        $this->get('/business-model')->assertRedirect(route('login'));
        $this->get('/business-model.csv')->assertRedirect(route('login'));
    }

    public function test_reviewers_and_admins_can_open_it_and_no_one_else(): void
    {
        foreach ([Role::Judge, Role::BidAdmin, Role::PlatformAdmin] as $role) {
            $this->actingAs($this->signIn($role))->get('/business-model')->assertOk();
            $this->actingAs($this->signIn($role))->get('/business-model.csv')->assertOk();
        }
        foreach ([Role::Employee, Role::EmployerAdmin, Role::Merchant] as $role) {
            $this->actingAs($this->signIn($role))->get('/business-model')->assertForbidden();
            $this->actingAs($this->signIn($role))->get('/business-model.csv')->assertForbidden();
        }

        $public = User::factory()->role(Role::Judge)->create(['reviewer_type' => ReviewerType::Public]);
        $this->actingAs($public)->get('/business-model')->assertOk();

        $contact = User::factory()->role(Role::Judge)->create(['reviewer_type' => ReviewerType::PersonalContact]);
        $this->actingAs($contact)->get('/business-model')->assertForbidden();
    }

    public function test_it_is_labelled_a_projection_and_links_its_assumptions(): void
    {
        $html = $this->actingAs($this->signIn(Role::Judge))->get('/business-model')->getContent();

        $this->assertGreaterThanOrEqual(3, substr_count($html, 'Projection, not results'));
        $this->assertStringContainsString('href="#assumptions"', $html);
        $this->assertStringContainsString('id="assumptions"', $html);
        $this->assertStringContainsString('Assumption: to validate in customer discovery', $html);
        $this->assertStringContainsString('a 2013 prepaid-card model shared by a mentor', $html);
        $this->assertStringContainsString('never revenue', $html);
        $this->assertStringContainsString(route('business-model.csv'), $html);
    }

    public function test_the_figures_come_from_the_calculator_for_each_scenario_and_case(): void
    {
        $model = BusinessModel::fromConfig();
        $judge = $this->signIn(Role::Judge);

        foreach (['a', 'b', 'c'] as $scenario) {
            foreach (['low', 'base', 'high'] as $case) {
                $p = $model->run($scenario, $case);
                $text = self::text($this->actingAs($judge)->get("/business-model?scenario={$scenario}&case={$case}")->assertOk()->getContent());

                foreach ($p->years() as $year) {
                    $this->assertStringContainsString(Figures::dollars($year['revenue']), $text, "{$scenario} {$case}");
                    $this->assertStringContainsString(Figures::dollars($year['ebitda']), $text, "{$scenario} {$case}");
                }
                $this->assertStringContainsString(Figures::dollars($p->peakCashNeeded()['amount']), $text);
                $this->assertStringContainsString($p->breakEvenMonth() === null ? 'Not reached' : 'Month '.$p->breakEvenMonth(), $text);
            }
        }
    }

    public function test_what_it_takes_is_shown_under_break_even(): void
    {
        $needs = BusinessModel::fromConfig()->whatItTakes('base');
        $text = self::text($this->actingAs($this->signIn(Role::BidAdmin))->get('/business-model')->getContent());

        $this->assertStringContainsString('What it takes', $text);
        $this->assertStringContainsString(Figures::count($needs->employees).' participating employees', $text);
        $this->assertStringContainsString('That is '.Figures::count($needs->employers).' employers', $text);
        $this->assertStringContainsString('An employer platform fee of '.Figures::exact($needs->breakEvenFee), $text);
        $this->assertLessThan(strpos($text, 'What it takes'), strpos($text, 'Monthly break-even'));
    }

    public function test_the_csv_has_the_label_every_scenario_case_and_year_and_what_it_takes(): void
    {
        $csv = $this->actingAs($this->signIn(Role::Judge))->get('/business-model.csv')->assertOk()->streamedContent();
        $rows = array_map(fn ($line) => str_getcsv($line, escape: ''), array_filter(explode("\n", trim($csv))));

        $this->assertStringStartsWith('Projection, not results', $rows[0][0]);
        $this->assertSame(BusinessModelController::CSV_HEADERS, $rows[1]);

        $yearly = BusinessModelController::rows(BusinessModel::fromConfig());
        $this->assertCount(3 * 3 * 4, $yearly);
        foreach ($yearly as $i => $row) {
            $this->assertSame(array_map('strval', $row), $rows[$i + 2]);
        }

        $this->assertStringContainsString('What it takes', $csv);
        $needs = BusinessModel::fromConfig()->whatItTakes('base');
        $this->assertStringContainsString(',base,'.$needs->employees.','.$needs->employers.',', $csv);
        $this->assertStringContainsString(number_format($needs->breakEvenFee, 2), $csv);
    }

    public function test_reviewers_find_it_from_their_home_and_admins_from_theirs(): void
    {
        $this->actingAs($this->signIn(Role::Judge))->get('/judge')->assertOk()->assertSee(route('business-model'), false);
        $this->actingAs($this->signIn(Role::PlatformAdmin))->get('/admin')->assertOk()->assertSee(route('business-model'), false);
        $this->actingAs($this->signIn(Role::BidAdmin))->get('/bid-admin/impact')->assertOk()->assertSee(route('business-model'), false);
        $this->get('/')->assertDontSee(route('business-model'), false);
    }
}
