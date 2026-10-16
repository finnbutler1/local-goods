<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Services\Ai\ClaudeClient;
use App\Services\EmployerProgram;
use App\Services\RedemptionService;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

/** The BID admin's participation overview and merchant roster. */
class ParticipationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->signIn(Role::BidAdmin);
    }

    public function test_overview_counts_cleared_dollars_only(): void
    {
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(90, 'hold'));
        app(RedemptionService::class)->redeem(Employee::orderBy('id')->firstOrFail(), Merchant::firstOrFail(), 1111);

        [$monthStart] = EmployerProgram::month();
        $month = (int) Redemption::where('status', Redemption::STATUS_APPROVED)->where('created_at', '>=', $monthStart)->sum('amount_cents');
        $allTime = (int) Redemption::where('status', Redemption::STATUS_APPROVED)->sum('amount_cents');

        $this->get('/bid-admin/participation')->assertOk()
            ->assertSee('Dollars recirculated')
            ->assertSee(Money::format($month))
            ->assertSee(Money::format($allTime).' since launch')
            ->assertSee('$11.11 more is held for review and not counted yet')
            ->assertSee('of 40');
    }

    public function test_roster_lists_every_fictional_merchant(): void
    {
        $response = $this->get('/bid-admin/participation')->assertSee('Merchant roster');

        foreach (Merchant::pluck('name') as $name) {
            $response->assertSee($name);
        }
    }

    public function test_it_is_in_the_bid_admin_menu_and_open_to_judges_only_to_read(): void
    {
        $this->get('/bid-admin')->assertSee(route('bid-admin.participation'));

        $this->signIn(Role::Judge);
        $this->get('/bid-admin/participation')->assertOk();

        foreach ([Role::Employee, Role::Merchant, Role::EmployerAdmin] as $role) {
            $this->signIn($role);
            $this->get('/bid-admin/participation')->assertForbidden();
        }
    }
}
