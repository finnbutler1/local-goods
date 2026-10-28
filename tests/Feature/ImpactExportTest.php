<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Http\Controllers\BidAdmin\ImpactExportController;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Services\BidImpactMetrics;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The district's aggregate data (ADR 0031): the same totals as the impact
 * page, counts and sums only, with no one named and no fee or revenue.
 */
class ImpactExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_the_csv_has_the_impact_totals_and_names_no_one(): void
    {
        $this->signIn(Role::BidAdmin);

        $csv = $this->get('/bid-admin/impact.csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
        $rows = array_map(fn ($line) => str_getcsv($line, escape: ''), array_filter(explode("\n", $csv)));
        $totals = app(BidImpactMetrics::class)->totals();

        $this->assertSame(ImpactExportController::HEADERS, $rows[0]);
        $this->assertSame(['Since launch', (string) $totals['visits'], Money::toDollars($totals['spent_cents'])], array_slice($rows[1], 0, 3));
        $this->assertCount(2 + 8, array_slice($rows, 1), 'since launch, this month and eight weeks');

        $names = [...Employee::pluck('display_name'), ...Employer::pluck('name'), ...Merchant::pluck('name'), 'Jordan', 'Rivera'];
        foreach ($names as $name) {
            $this->assertStringNotContainsString(preg_replace('/\s*\(.*\)/', '', $name), $csv);
        }
        $this->assertDoesNotMatchRegularExpression('/fee|revenue/i', $csv);
    }

    public function test_judges_can_download_it_and_employees_cannot(): void
    {
        $this->signIn(Role::Judge);
        $this->get('/bid-admin/impact.csv')->assertOk();

        $this->signIn(Role::Employee);
        $this->get('/bid-admin/impact.csv')->assertForbidden();
    }
}
