<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** District Map and Merchant Eval are in the judge's own menu and highlight when open. */
class JudgeMenuItemsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** @return array<string, array{0: ReviewerType}> */
    public static function judges(): array
    {
        return ['public judge' => [ReviewerType::Public], 'named judge' => [ReviewerType::ContestJudge]];
    }

    #[DataProvider('judges')]
    public function test_both_items_appear_and_are_current_on_their_pages(ReviewerType $type): void
    {
        $this->actingAs(User::factory()->role(Role::Judge)->create(['reviewer_type' => $type]));

        foreach (['district' => 'District Map', 'eval.merchants' => 'Merchant Eval'] as $route => $label) {
            $html = $this->get(route($route))->assertOk()->getContent();
            $sidebar = str($html)->after('<aside')->before('</aside>')->toString();
            $this->assertStringContainsString('href="'.route('district').'"', $sidebar);
            $this->assertStringContainsString('href="'.route('eval.merchants').'"', $sidebar);
            $this->assertMatchesRegularExpression('#href="'.preg_quote(route($route), '#').'"\s+aria-current="page"[^>]*>[\s\S]*?'.$label.'#', $sidebar, $label);
        }
    }

    public function test_the_judge_menu_still_fits_the_sidebar_and_tab_bar(): void
    {
        $this->assertSame(['dashboard', 'business-model', 'district', 'merchant-eval'], array_keys(Role::Judge->navigation()));
    }
}
