<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantProduct;
use App\Services\Eligibility\MerchantCandidates;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Product menus for the fictional demo merchants (ADR 0023). Display only:
 * the redemption amount is still the total, and Redemption Fraud Detection
 * never sees products (see RedemptionItemsTest).
 */
class ProductMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_every_demo_merchant_has_four_to_eight_products(): void
    {
        $this->assertSame(array_keys(DatabaseSeeder::DEMO_MERCHANTS), array_keys(DatabaseSeeder::DEMO_MENUS));

        foreach (DatabaseSeeder::DEMO_MERCHANTS as [$name]) {
            $count = Merchant::where('name', $name)->firstOrFail()->products()->count();
            $this->assertGreaterThanOrEqual(4, $count, $name);
            $this->assertLessThanOrEqual(8, $count, $name);
        }

        $this->assertSame(0, MerchantProduct::where('price_cents', '<=', 0)->count());
    }

    public function test_real_candidates_never_have_products(): void
    {
        $candidateNames = array_map(fn ($c) => mb_strtolower($c['name']), MerchantCandidates::load());
        $demoNames = array_column(DatabaseSeeder::DEMO_MERCHANTS, 0);

        foreach (MerchantProduct::with('merchant')->get() as $product) {
            $this->assertContains($product->merchant->name, $demoNames);
            $this->assertNotContains(mb_strtolower($product->merchant->name), $candidateNames);
        }
    }

    public function test_a_product_cannot_be_saved_for_a_merchant_outside_the_demo(): void
    {
        $candidate = collect(MerchantCandidates::load())->first();
        $real = Merchant::create(['bid_id' => 1, 'name' => $candidate['name'], 'category' => 'restaurant', 'address' => 'x']);
        $other = Merchant::create(['bid_id' => 1, 'name' => 'Not In The Demo', 'category' => 'cafe', 'address' => 'x']);

        foreach ([$real, $other] as $merchant) {
            $this->assertFalse($merchant->mayHaveProducts());
            try {
                MerchantProduct::create(['merchant_id' => $merchant->id, 'name' => 'Latte', 'price_cents' => 500]);
                $this->fail('A product was saved for '.$merchant->name);
            } catch (LogicException) {
                $this->assertSame(0, $merchant->products()->count());
            }
        }
    }
}
