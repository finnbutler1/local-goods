<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\Employer;
use App\Models\Merchant;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Ai\ClaudeClient;
use App\Services\DemoResetter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

class ResetDemoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 12:00', 'America/New_York'));
        $this->seed(DatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_reset_restores_seeded_demo_data_and_leaves_other_tables_alone(): void
    {
        // Non-demo data that must survive the reset.
        $user = User::factory()->create();
        DB::table('cache')->insert(['key' => 'keep-me', 'value' => 'x', 'expiration' => 0]);
        DB::table('sessions')->insert(['id' => 'keep-me', 'payload' => 'x', 'last_activity' => 0]);

        $seeded = $this->demoState();

        // Make a held redemption during the "demo".
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(95, 'hold', 'Full balance.'));
        $deli = Merchant::where('name', 'Press Room Deli')->firstOrFail();
        $this->signIn(Role::Employee);
        $this->post('/employee/redeem', ['merchant_id' => $deli->id, 'amount' => '120']);
        $this->assertSame(0, Employee::firstOrFail()->balanceCents());
        $this->assertSame($seeded['redemptions'] + 1, Redemption::count());

        $this->signIn(Role::BidAdmin);
        $this->followingRedirects()
            ->post('/bid-admin/reset')
            ->assertOk()
            ->assertSee('Demo data has been reset')
            ->assertDontSee('Full balance.');

        // Seeded state is back, exactly once: same rows, same balances.
        $this->assertSame($seeded, $this->demoState());
        $this->assertSame(0, Redemption::where('status', Redemption::STATUS_UNDER_REVIEW)->count());
        $this->assertSame(DatabaseSeeder::ENROLLED, Employee::count());
        $this->assertSame(8, Merchant::count());
        $this->assertSame(12000, Employee::firstOrFail()->balanceCents());

        // Non-demo tables untouched.
        $this->assertTrue(User::whereKey($user->id)->exists());
        $this->assertTrue(DB::table('cache')->where('key', 'keep-me')->exists());
        $this->assertTrue(DB::table('sessions')->where('id', 'keep-me')->exists());
    }

    public function test_reset_only_ever_clears_the_demo_tables(): void
    {
        // Adding a table here (users, sessions, feedback...) would let Reset
        // demo delete real data. Change this list only on purpose. The
        // correction tables (ADR 0028) are demo data like the ledger. The
        // partnership tables (ADR 0031) point at bids, which a reset clears,
        // so the demo partnership is reset with them. Applications point at
        // merchants: Reset demo clears them too and restores the two samples.
        // The demo activity generator's log (ADR 0032) points at redemptions
        // and purchases, so it goes with them (Reset demo is local only).
        $this->assertSame(
            ['demo_activity_actions', 'demo_activity_days', 'merchant_application_events', 'merchant_applications', 'partnership_changes', 'partnerships', 'pay_codes', 'credit_ledger_entries', 'correction_actions', 'correction_items', 'corrections', 'redemption_decisions', 'merchant_payouts', 'redemption_items', 'credit_allocations', 'credit_purchases', 'redemptions', 'merchant_products', 'merchants', 'employees', 'employers', 'bids'],
            DemoResetter::DEMO_TABLES,
        );
    }

    public function test_admin_view_has_the_reset_button(): void
    {
        $this->signIn(Role::BidAdmin);
        $this->get('/bid-admin')
            ->assertOk()
            ->assertSee('Reset demo')
            ->assertSee(route('bid-admin.reset'), false);
    }

    public function test_reset_requires_post(): void
    {
        $this->signIn(Role::BidAdmin);
        $redemptions = Redemption::count();
        $this->get('/bid-admin/reset')->assertMethodNotAllowed();
        $this->assertSame($redemptions, Redemption::count());
    }

    /**
     * Row counts per demo table and every balance, with ids left out (MySQL
     * keeps counting ids up after a reset).
     *
     * @return array<string, mixed>
     */
    private function demoState(): array
    {
        return [
            'redemptions' => Redemption::count(),
            'tables' => collect(DemoResetter::DEMO_TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all(),
            'employees' => Employee::orderBy('id')->get()->map(fn ($e) => [$e->display_name, $e->balanceCents()])->all(),
            'merchants' => Merchant::orderBy('id')->get()->map(fn ($m) => [$m->name, $m->payableCents()])->all(),
            'employer' => Employer::firstOrFail()->balanceCents(),
        ];
    }
}
