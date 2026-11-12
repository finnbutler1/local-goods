<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CreditPurchase;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsOnAFixedDate;
use Tests\TestCase;

/**
 * The shared demo employer login buys at most $5,000.00 at a time, so a judge
 * can't add $999,999.99 and distort Where the Money Is. Any other employer
 * admin keeps the form's normal limit. Simulated mode (no Stripe keys).
 */
class DemoPurchaseCapTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOnAFixedDate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOnAFixedDate();
        $this->seed(DatabaseSeeder::class);
    }

    private function demoEmployer(): User
    {
        return User::factory()->role(Role::EmployerAdmin)->create(['email' => config('demo.accounts.employer_admin.email')]);
    }

    public function test_the_cap_is_five_thousand_dollars(): void
    {
        $this->assertSame(500_000, config('demo.employer_purchase_cap_cents'));
    }

    public function test_the_demo_employer_can_buy_up_to_the_cap(): void
    {
        $this->actingAs($this->demoEmployer());
        $before = CreditPurchase::count();

        $this->post('/employer/purchases', ['purchase_amount' => '5000.00'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($before + 1, CreditPurchase::count());
        $this->assertSame(500_000, CreditPurchase::latest('id')->firstOrFail()->amount_cents);
    }

    public function test_the_demo_employer_is_refused_above_the_cap_and_nothing_is_written(): void
    {
        $this->actingAs($this->demoEmployer());
        $before = CreditPurchase::count();

        foreach (['5000.01', '999999.99'] as $amount) {
            $this->from('/employer')->post('/employer/purchases', ['purchase_amount' => $amount])
                ->assertRedirect('/employer')
                ->assertSessionHasErrors(['purchase_amount' => 'The demo employer can buy up to $5,000.00 at a time.']);
        }

        $this->assertSame($before, CreditPurchase::count());
    }

    public function test_another_employer_admin_keeps_the_normal_limit(): void
    {
        $this->actingAs(User::factory()->role(Role::EmployerAdmin)->create(['email' => 'hr.lead@example.com']));
        $before = CreditPurchase::count();

        $this->post('/employer/purchases', ['purchase_amount' => '10000'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($before + 1, CreditPurchase::count());
        $this->assertSame(1_000_000, CreditPurchase::latest('id')->firstOrFail()->amount_cents);
    }
}
