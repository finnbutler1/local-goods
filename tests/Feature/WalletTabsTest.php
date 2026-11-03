<?php

namespace Tests\Feature;

use App\Enums\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The two wallet tabs: big, an icon and a line of help each, and a selected state that is not only colour. */
class WalletTabsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->signIn(Role::Employee);
    }

    private function tab(string $html, string $key): string
    {
        preg_match('#<a role="tab" id="tab-'.$key.'".*?</a>#s', $html, $m);

        return $m[0] ?? '';
    }

    public function test_each_tab_has_an_icon_a_label_and_a_one_line_description(): void
    {
        $html = $this->get('/employee')->assertOk()->getContent();

        $toGo = $this->tab($html, 'to-go');
        $inPerson = $this->tab($html, 'in-person');

        $this->assertStringContainsString('Order to go', $toGo);
        $this->assertStringContainsString('Order ahead, pick it up', $toGo);
        $this->assertStringContainsString('Pay in person', $inPerson);
        $this->assertStringContainsString('Show a code at the counter', $inPerson);
        foreach ([$toGo, $inPerson] as $tab) {
            $this->assertStringContainsString('<svg', $tab);
            $this->assertStringNotContainsString('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-7">', $tab);
            $this->assertStringContainsString('aria-hidden="true"', $tab);
            $this->assertStringContainsString('min-h-20', $tab);
        }
    }

    public function test_the_selected_tab_is_marked_by_more_than_colour_and_the_mark_follows_aria_selected(): void
    {
        foreach (['to-go' => 'in-person', 'in-person' => 'to-go'] as $selected => $other) {
            $html = $this->get('/employee?tab='.$selected)->assertOk()->getContent();

            $this->assertStringContainsString('aria-selected="true"', $this->tab($html, $selected));
            $this->assertStringContainsString('aria-selected="false"', $this->tab($html, $other));
        }

        // A check mark, an underlined label and a heavy bar, each only under aria-selected.
        $tab = $this->tab($this->get('/employee')->getContent(), 'to-go');
        $this->assertStringContainsString('group-aria-selected:block', $tab);
        $this->assertStringContainsString('group-aria-selected:underline', $tab);
        $this->assertStringContainsString('aria-selected:shadow-[inset_0_-6px_0_0', $tab);
        $this->assertStringContainsString('hidden size-5', $tab, 'The check mark is hidden until the tab is selected.');
    }

    public function test_the_tab_semantics_and_phone_layout_are_kept(): void
    {
        $html = $this->get('/employee')->getContent();

        $this->assertStringContainsString('role="tablist" aria-label="How to pay"', $html);
        $this->assertStringContainsString('grid grid-cols-2', $html);
        $this->assertSame(2, substr_count($html, 'role="tab"'));
        $this->assertSame(2, substr_count($html, 'aria-controls="panel-'));
        $this->assertStringNotContainsString('max-sm:hidden', $this->tab($html, 'to-go').$this->tab($html, 'in-person'));
    }
}
