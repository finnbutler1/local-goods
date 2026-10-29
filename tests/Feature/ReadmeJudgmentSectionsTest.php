<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The README states, in plain words, what we chose not to build, who we
 * built for and what keeps the money and data safe. Words only: this checks
 * the sections are still there (and the public table is on the Who does what
 * page), not what they say.
 */
class ReadmeJudgmentSectionsTest extends TestCase
{
    use RefreshDatabase;

    private function section(string $heading): string
    {
        $readme = file_get_contents(base_path('README.md'));
        $this->assertSame(1, preg_match('/^## '.preg_quote($heading, '/').'\R(.*?)(?=^## |\z)/ms', $readme, $m), "README has no \"## $heading\" section.");

        return $m[1];
    }

    public function test_the_three_sections_exist_and_are_not_empty(): void
    {
        foreach ([
            'What we chose not to build, and why',
            'Designed for the people in the transaction',
            'Safety and privacy from the start',
        ] as $heading) {
            $this->assertGreaterThan(200, strlen(trim($this->section($heading))), "\"$heading\" is too short.");
        }
    }

    public function test_the_chose_not_to_build_table_has_at_least_six_decisions(): void
    {
        $rows = array_filter(
            preg_split('/\R/', $this->section('What we chose not to build, and why')),
            fn (string $line) => str_starts_with($line, '|') && ! preg_match('/^\|\s*(-+|Decision)\b/', $line),
        );

        $this->assertGreaterThanOrEqual(6, count($rows));
        foreach ($rows as $row) {
            $this->assertCount(2, array_filter(array_map('trim', explode('|', trim($row, '|'))), fn ($cell) => $cell !== ''), "Every row needs a decision and a reason: $row");
        }
    }

    public function test_the_public_page_carries_the_same_table(): void
    {
        $html = $this->get('/who-does-what')->assertOk()->getContent();

        $this->assertStringContainsString('id="not-built"', $html);
        $this->assertStringContainsString('What we chose not to build, and why', $html);
        $this->get('/')->assertOk()->assertSee('who-does-what#not-built', false);
    }
}
