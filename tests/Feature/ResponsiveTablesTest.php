<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Support\ViewAs;
use Database\Seeders\DatabaseSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SweepsPages;
use Tests\TestCase;

/**
 * Every table uses the one responsive pattern (<x-rtable>, "Responsive
 * tables" in resources/css/app.css): data-rtable="stack" turns rows into
 * cards on phones and every body cell says which column it is, so the card
 * can show (and a screen reader can read) "Email: …" instead of a bare value.
 * data-rtable="fit" is only for a short table that fits a 320px phone as it is.
 */
class ResponsiveTablesTest extends TestCase
{
    use RefreshDatabase;
    use SweepsPages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** @return list<string> */
    private static function problems(string $html): array
    {
        $doc = new DOMDocument;
        @$doc->loadHTML($html);
        $xpath = new DOMXPath($doc);
        $problems = [];

        foreach ($xpath->query('//table') as $table) {
            /** @var DOMElement $table */
            $name = trim($xpath->evaluate('string(caption)', $table)) ?: 'a table';
            $mode = $table->getAttribute('data-rtable');

            if (! in_array($mode, ['stack', 'fit'], true)) {
                $problems[] = "{$name}: no data-rtable=\"stack\" (or \"fit\")";

                continue;
            }
            if ($mode === 'fit') {
                continue;
            }
            if (! $table->parentNode instanceof DOMElement || $table->parentNode->getAttribute('role') !== 'region') {
                $problems[] = "{$name}: not inside <x-rtable> (a labelled region)";
            }
            foreach ($xpath->query('tbody/tr/*|tfoot/tr/*', $table) as $cell) {
                /** @var DOMElement $cell */
                if (! $cell->hasAttribute('data-label') && ! $cell->hasAttribute('data-primary')) {
                    $problems[] = "{$name}: cell \"".mb_substr(trim($cell->textContent), 0, 30).'" has no data-label or data-primary';
                }
            }
        }

        return array_values(array_unique($problems));
    }

    public function test_every_table_on_every_page_uses_the_responsive_pattern(): void
    {
        $found = [];
        $tables = 0;
        $this->sweepPages(function (string $html, string $where) use (&$found, &$tables) {
            $tables += substr_count($html, '<table');
            if ($problems = self::problems($html)) {
                $found[$where] = $problems;
            }
        });

        $judge = $this->signIn(Role::Judge);
        foreach (ViewAs::ROLES as $role) {
            $html = $this->actingAs($judge)->followingRedirects()->get(route('judge.view-as', $role->value))->assertOk()->getContent();
            if ($problems = self::problems($html)) {
                $found["view as {$role->value}"] = $problems;
            }
        }

        // The styleguide shows the pattern; it only opens locally.
        $this->app['env'] = 'local';
        auth()->logout();
        if ($problems = self::problems($this->get('/styleguide')->assertOk()->getContent())) {
            $found['/styleguide'] = $problems;
        }

        $this->assertGreaterThan(10, $tables, 'Too few tables were checked.');
        $this->assertSame([], $found, "Tables outside the responsive pattern:\n".collect($found)->flatten()->unique()->implode("\n"));
    }

    public function test_the_admin_accounts_table_stacks_with_labelled_cells(): void
    {
        $html = $this->actingAs($this->signIn(Role::PlatformAdmin))->get('/admin')->assertOk()->getContent();

        $this->assertStringContainsString('data-rtable="stack"', $html);
        $this->assertStringContainsString('data-label="Email"', $html);
        $this->assertStringContainsString('aria-label="Accounts table"', $html);
    }

    public function test_the_stylesheet_turns_stacked_tables_into_cards_on_phones(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression('/@media \(width < 40rem\)\s*\{\s*table\[data-rtable=\'stack\'\]/', $css);
        $this->assertStringContainsString('content: attr(data-label)', $css);
        // Safari drops table semantics once the cells are blocks; app.js puts them back.
        $this->assertStringContainsString("setAttribute('role', 'table')", file_get_contents(resource_path('js/app.js')));
    }
}
