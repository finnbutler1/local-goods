<?php

namespace Tests\Feature;

use App\Support\Projections\BusinessModel;
use App\Support\Projections\Figures;
use App\Support\Projections\ProjectionDocs;
use Tests\TestCase;

/**
 * The projection figures in the README and docs/finance/MODEL.md (ADR 0030)
 * are generated from config/projections.php, so they can't drift: change an
 * assumption and this fails until `php artisan projections:docs` runs.
 */
class BusinessModelDocsTest extends TestCase
{
    public function test_the_readme_and_model_docs_match_the_model(): void
    {
        $docs = new ProjectionDocs(BusinessModel::fromConfig());

        foreach (array_keys(ProjectionDocs::FILES) as $file) {
            $current = file_get_contents(base_path($file));
            $this->assertSame($docs->apply($current, $file), $current, "{$file} is out of date: run php artisan projections:docs");
        }
    }

    public function test_a_changed_assumption_changes_the_generated_figures(): void
    {
        $readme = file_get_contents(base_path('README.md'));
        $changed = new ProjectionDocs(BusinessModel::fromConfig()->with(['sales_cost_per_new_employer' => 0, 'bid_launch_cost' => 0]));

        $this->assertNotSame($readme, $changed->apply($readme, 'README.md'));
    }

    public function test_the_readme_table_is_base_case_revenue_and_ebitda_for_every_scenario(): void
    {
        $readme = file_get_contents(base_path('README.md'));
        $section = str($readme)->after("## Business model\n")->before("\n## ")->toString();
        $model = BusinessModel::fromConfig();

        $this->assertStringContainsString('Projection, not results', $section);
        $this->assertStringContainsString('docs/finance/MODEL.md', $section);
        foreach (config('projections.scenarios') as $key => $scenario) {
            $years = $model->run($key)->years();
            $this->assertStringContainsString("| {$scenario['name']}: revenue | ".implode(' | ', array_map(fn ($y) => Figures::dollars($y['revenue']), $years)).' |', $section);
            $this->assertStringContainsString("| {$scenario['name']}: EBITDA | ".implode(' | ', array_map(fn ($y) => Figures::dollars($y['ebitda']), $years)).' |', $section);
        }
    }

    public function test_the_model_docs_name_every_assumption_and_the_keys_under_review(): void
    {
        $model = file_get_contents(base_path('docs/finance/MODEL.md'));

        foreach (array_keys(config('projections.assumptions')) as $key) {
            $this->assertStringContainsString("`{$key}`", $model);
        }
        $this->assertStringContainsString('Projection, not results', $model);
        $this->assertStringContainsString('Breakage is excluded from revenue by policy', $model);
        $this->assertStringContainsString('a 2013 prepaid-card model shared by a mentor', $model);
        $this->assertStringContainsString('What customer discovery must validate', $model);
        $this->assertStringContainsString('## What it takes', $model);
    }
}
