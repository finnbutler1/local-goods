<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Merchant;
use App\Models\MerchantProduct;
use App\Models\Redemption;
use App\Services\Ai\ClaudeClient;
use App\Services\DemoResetter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/**
 * Priya's menu page (ADR 0023): she can see her menu, change a product,
 * take it off the menu and add one. Nobody else can change it.
 */
class MerchantMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function grind(): Merchant
    {
        return Merchant::where('name', 'Farragut Grind')->firstOrFail();
    }

    private function latte(): MerchantProduct
    {
        return $this->grind()->products()->where('name', 'Latte')->firstOrFail();
    }

    public function test_the_merchant_sees_her_menu(): void
    {
        $this->signIn(Role::Merchant);

        $this->get('/merchant')->assertOk()->assertSee('See and change your menu');
        $this->get('/merchant/menu')->assertOk()
            ->assertSee('Your menu')
            ->assertSee('Latte')
            ->assertSee('$5.75')
            ->assertSee('Redemption Fraud Detection checks the amount, never the items');
    }

    public function test_changing_a_price_applies_to_new_redemptions_only(): void
    {
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(5, 'clear'));
        $latte = $this->latte();
        $this->actingAs($this->signIn(Role::Employee))->post('/employee/redeem', ['merchant_id' => $this->grind()->id, 'items' => [$latte->id => 1]]);
        $before = Redemption::with('items')->latest('id')->firstOrFail();

        $this->actingAs($this->signIn(Role::Merchant))
            ->post(route('merchant.menu.update', $latte), ['name' => 'Oat latte', 'price' => '6.25', 'description' => 'With oat milk', 'is_available' => '1'])
            ->assertRedirect(route('merchant.menu').'#product-'.$latte->id)
            ->assertSessionHas('status', 'Oat latte saved: $6.25, on the menu.');

        $this->assertSame(['Oat latte', 625, 'With oat milk', true], [$latte->fresh()->name, $latte->fresh()->price_cents, $latte->fresh()->description, $latte->fresh()->is_available]);
        $this->assertSame('1× Latte', $before->fresh()->load('items')->itemsSummary());
        $this->assertSame(633, $before->fresh()->amount_cents); // $5.75 + 10% DC meals tax
    }

    public function test_a_product_taken_off_the_menu_disappears_from_the_redeem_screen(): void
    {
        $latte = $this->latte();
        $this->actingAs($this->signIn(Role::Merchant))
            ->post(route('merchant.menu.update', $latte), ['name' => 'Latte', 'price' => '5.75'])
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'taken off the menu'));

        $this->assertFalse($latte->fresh()->is_available);
        $this->actingAs($this->signIn(Role::Employee))->get('/employee?merchant='.$this->grind()->id)
            ->assertSee('One more Cappuccino')->assertDontSee('One more Latte');
    }

    public function test_adding_a_product(): void
    {
        $this->signIn(Role::Merchant);

        $this->post(route('merchant.menu.store'), ['name' => 'Matcha latte', 'price' => '6.50'])->assertSessionHas('status', 'Matcha latte added to the menu at $6.50.');

        $added = $this->grind()->products()->get()->last();
        $this->assertSame(['Matcha latte', 650, true], [$added->name, $added->price_cents, $added->is_available]);
    }

    public function test_bad_values_are_refused_with_the_error_by_the_field(): void
    {
        $this->signIn(Role::Merchant);
        $latte = $this->latte();

        $this->from('/merchant/menu')->post(route('merchant.menu.update', $latte), ['name' => '', 'price' => '0'])
            ->assertSessionHasErrorsIn('product'.$latte->id, ['name', 'price']);
        $this->from('/merchant/menu')->post(route('merchant.menu.store'), ['name' => 'X', 'price' => 'free'])
            ->assertSessionHasErrorsIn('add', ['price']);
        $this->assertSame(575, $latte->fresh()->price_cents);
    }

    public function test_the_menu_holds_at_most_twelve_products(): void
    {
        $this->signIn(Role::Merchant);
        $grind = $this->grind();
        while ($grind->products()->count() < 12) {
            $grind->products()->create(['name' => 'Extra', 'price_cents' => 100]);
        }

        $this->post(route('merchant.menu.store'), ['name' => 'One too many', 'price' => '1'])->assertSessionHas('error');
        $this->assertSame(12, $grind->products()->count());
        $this->get('/merchant/menu')->assertDontSee('Add a product');
    }

    public function test_another_merchants_product_cannot_be_changed(): void
    {
        $this->signIn(Role::Merchant);
        $deli = Merchant::where('name', 'Press Room Deli')->firstOrFail()->products()->firstOrFail();

        $this->post(route('merchant.menu.update', $deli), ['name' => 'Hacked', 'price' => '1'])->assertForbidden();
        $this->assertNotSame('Hacked', $deli->fresh()->name);
    }

    public function test_other_roles_cannot_open_or_change_a_menu(): void
    {
        foreach ([Role::Employee, Role::EmployerAdmin, Role::BidAdmin] as $role) {
            $this->actingAs($this->signIn($role));
            $this->get('/merchant/menu')->assertForbidden();
            $this->post(route('merchant.menu.update', $this->latte()), ['name' => 'Hacked', 'price' => '1'])->assertForbidden();
        }

        $this->assertSame('Latte', $this->latte()->name);
    }

    public function test_a_judge_sees_the_demo_menu_read_only(): void
    {
        $this->signIn(Role::Judge);
        $this->get('/judge/view-as/merchant');

        $html = $this->get('/merchant/menu')->assertOk()
            ->assertSee("Read-only: judges can see Farragut Grind's menu", false)
            ->getContent();
        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*>\s*Save Latte/s', $html);

        $this->post(route('merchant.menu.update', $this->latte()), ['name' => 'Hacked', 'price' => '1'])->assertForbidden();
        $this->post(route('merchant.menu.store'), ['name' => 'Hacked', 'price' => '1'])->assertForbidden();
        $this->assertSame(0, MerchantProduct::where('name', 'Hacked')->count());
    }

    public function test_reset_demo_puts_the_original_menu_back(): void
    {
        $this->latte()->update(['price_cents' => 999]);
        $this->grind()->products()->create(['name' => 'Extra', 'price_cents' => 100]);

        app(DemoResetter::class)->reset();

        $this->assertSame(575, $this->latte()->price_cents);
        $this->assertSame(collect(DatabaseSeeder::DEMO_MENUS)->flatten(1)->count(), MerchantProduct::count());
    }
}
