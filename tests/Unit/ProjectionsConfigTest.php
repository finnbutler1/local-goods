<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The business model's assumptions (ADR 0030): every one says where it comes
 * from, or is labelled an assumption to check in customer discovery, and has
 * a low ≤ base ≤ high range and the date it was checked.
 */
class ProjectionsConfigTest extends TestCase
{
    private const ASSUMPTION = 'Assumption: to validate in customer discovery';

    /** @return array<string, mixed> */
    private static function config(): array
    {
        return require __DIR__.'/../../config/projections.php';
    }

    public function test_every_assumption_has_a_range_unit_date_and_source_or_the_assumption_label(): void
    {
        $config = self::config();
        $this->assertSame(self::ASSUMPTION, $config['assumption_label']);
        $this->assertGreaterThan(30, count($config['assumptions']));

        foreach ($config['assumptions'] as $key => $a) {
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $key);
            $this->assertNotSame('', $a['label'], $key);
            $this->assertNotSame('', $a['unit'], $key);
            $this->assertLessThanOrEqual($a['base'], $a['low'], $key);
            $this->assertLessThanOrEqual($a['high'], $a['base'], $key);
            $this->assertGreaterThanOrEqual(0, $a['low'], $key);
            $this->assertSame($a['low'] != $a['high'], $a['varies'], $key);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $a['verified_on'], $key);

            if ($a['source_url'] === null) {
                $this->assertSame(self::ASSUMPTION, $a['source'], "{$key} has no source link, so it must be labelled an assumption.");
            } else {
                $this->assertStringStartsWith('https://', $a['source_url'], $key);
                $this->assertNotSame(self::ASSUMPTION, $a['source'], "{$key} has a source link, so say what it shows.");
            }
        }
    }

    public function test_only_demand_and_pricing_drivers_vary_between_cases(): void
    {
        foreach (self::config()['assumptions'] as $key => $a) {
            if ($a['varies']) {
                $this->assertContains($a['group'], ['demand', 'revenue'], "{$key}: costs stay the same in every case.");
            }
        }
    }

    public function test_only_demand_assumptions_are_shown_on_the_public_page(): void
    {
        foreach (self::config()['assumptions'] as $key => $a) {
            if ($a['public']) {
                $this->assertSame('demand', $a['group'], $key);
            }
        }
    }

    public function test_the_go_to_market_costs_under_review_are_named_keys_with_a_note(): void
    {
        $assumptions = self::config()['assumptions'];

        foreach (['account_manager_bids_per_manager' => 5, 'sales_cost_per_new_employer' => 1500, 'bid_launch_cost' => 10000] as $key => $value) {
            $this->assertEquals($value, $assumptions[$key]['base'], $key);
            $this->assertStringContainsString('Under review', $assumptions[$key]['note'], $key);
        }
    }

    public function test_scenarios_grow_and_dc_wide_stops_at_the_dc_count(): void
    {
        $scenarios = self::config()['scenarios'];

        $this->assertSame(['a', 'b', 'c'], array_keys($scenarios));
        $this->assertSame([1, 1, 1, 1], $scenarios['a']['bids_by_year_end']);
        $this->assertLessThanOrEqual(13, max($scenarios['b']['bids_by_year_end']), 'DSLBD counts 12 DC BIDs (and lists 13).');
        foreach ([0, 1, 2, 3] as $year) {
            $this->assertLessThanOrEqual($scenarios['b']['bids_by_year_end'][$year], $scenarios['a']['bids_by_year_end'][$year]);
            $this->assertLessThanOrEqual($scenarios['c']['bids_by_year_end'][$year], $scenarios['b']['bids_by_year_end'][$year]);
        }
        foreach ($scenarios as $key => $scenario) {
            $this->assertSame($scenario['source_url'] === null, $scenario['source'] === self::ASSUMPTION, $key);
        }
    }

    public function test_card_issuing_is_off(): void
    {
        $this->assertFalse(self::config()['card_issuing']['enabled']);
    }

    public function test_the_reference_model_is_described_without_naming_it_or_its_author(): void
    {
        $config = self::config();
        $text = json_encode($config['reference']);

        $this->assertSame('a 2013 prepaid-card model shared by a mentor', $config['reference']['description']);
        $this->assertStringNotContainsStringIgnoringCase('In'.'City', $text);
        $this->assertStringNotContainsStringIgnoringCase('In'.'City', file_get_contents(__DIR__.'/../../config/projections.php'));
    }
}
