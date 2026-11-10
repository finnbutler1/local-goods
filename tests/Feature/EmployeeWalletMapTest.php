<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\Merchant;
use App\View\Components\DistrictDiagram;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The district map at the top of "Where you can spend": the merchants the
 * employee can spend at, drawn from the stored boundary (no outside map).
 */
class EmployeeWalletMapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->withoutVite();
        $this->signIn(Role::Employee);
    }

    public function test_the_map_has_a_stop_for_each_merchant_and_each_links_to_its_list_item(): void
    {
        $html = $this->get('/employee')->assertOk()->getContent();

        $spendable = Merchant::redeemableFor(Employee::orderBy('id')->firstOrFail())->orderBy('name')->get();
        $this->assertGreaterThan(0, $spendable->count());
        $this->assertSame($spendable->count(), substr_count($html, 'data-stop-link'));
        foreach ($spendable as $merchant) {
            $this->assertStringContainsString('data-stop="'.e($merchant->name).'"', $html);
            $this->assertStringContainsString('href="#merchant-'.$merchant->id.'" class="dd-link"', $html);
            $this->assertMatchesRegularExpression('/<li id="merchant-'.$merchant->id.'" tabindex="-1"/', $html);
            $this->assertStringContainsString(e($merchant->address), $html);
        }
        $this->assertStringContainsString('role="group"', $html);
        $this->assertStringContainsString('aria-label="Demo merchants on the map"', $html);
        $this->assertMatchesRegularExpression('#<svg[^>]*data-district-diagram[^>]*>(?:(?!</svg>).)*<a [^>]*href="\#merchant-#s', $html);
        $this->assertDoesNotMatchRegularExpression('/<svg[^>]*>(?:(?!<\/svg>).)*(?:href|src)="https?:/s', $html, 'no outside request in the map');
        $this->assertStringNotContainsString('Local stop', $html, 'no local stop in the wallet');
    }

    public function test_a_merchant_that_is_not_spendable_is_not_a_stop(): void
    {
        Merchant::where('name', 'Triangle Stems')->update(['live_at' => null]);

        $html = $this->get('/employee')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-stop="Triangle Stems"', $html);
        $this->assertStringContainsString('data-stop="Press Room Deli"', $html);
    }

    public function test_the_map_follows_the_category_filter(): void
    {
        $category = Merchant::where('name', 'Press Room Deli')->value('category');
        $html = $this->get('/employee?category='.$category)->assertOk()->getContent();

        $this->assertStringContainsString('data-stop="Press Room Deli"', $html);
        $this->assertSame(Merchant::where('category', $category)->count(), substr_count($html, 'data-stop-link'));
    }

    public function test_no_spendable_merchants_means_no_map_and_the_page_still_works(): void
    {
        Merchant::query()->update(['live_at' => null]);

        $html = $this->get('/employee')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-district-diagram', $html);
        $this->assertStringContainsString('Where you can spend', $html);
        $this->assertStringContainsString('No merchants match', $html);
    }

    public function test_a_merchant_without_an_address_shows_the_name_only(): void
    {
        Merchant::where('name', 'Triangle Stems')->update(['address' => '']);

        $html = $this->get('/employee')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#data-merchant-link[^>]*>Triangle Stems</a>\s*</li>#', $html);
    }

    public function test_linked_stop_labels_are_placed_clear_of_each_other_and_inside_the_map(): void
    {
        $point = fn (string $name, float $x, float $y) => ['name' => $name, 'lines' => [$name], 'x' => $x, 'y' => $y, 'side' => null, 'local' => false, 'href' => '#merchant-1'];
        $placed = DistrictDiagram::placeLabels([
            $point('Connecticut Noodle Bar', 800.0, 400.0),
            $point('Seventeenth Street Kitchen', 860.0, 400.0),
            $point('Farragut Grind', 1000.0, 500.0),
        ]);

        $this->assertNotSame($placed[0]['side'], $placed[1]['side']);
        $this->assertSame('left', $placed[2]['side'], 'a label that would run off the right edge goes to the left');
        // Stops that are not linked and name no side keep the landing page default.
        $this->assertSame('right', DistrictDiagram::placeLabels([['name' => 'A', 'lines' => ['A'], 'x' => 10.0, 'y' => 10.0, 'side' => null, 'local' => false, 'href' => null]])[0]['side']);
    }
}
