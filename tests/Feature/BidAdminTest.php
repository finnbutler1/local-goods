<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Merchant;
use App\Services\Ai\ClaudeClient;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeClaudeClient;
use Tests\TestCase;

class BidAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_view_shows_decision_score_signals_and_explanation(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(91, 'hold', 'The full balance was redeemed as a round number.'));

        $deli = Merchant::where('name', 'Press Room Deli')->firstOrFail();
        $this->signIn(Role::Employee);
        $this->post('/employee/redeem', ['merchant_id' => $deli->id, 'amount' => '20']);

        $this->signIn(Role::BidAdmin);
        $this->get('/bid-admin')
            ->assertOk()
            ->assertSee('Redemption Fraud Detection')
            ->assertSee('$20.00 at Press Room Deli')
            ->assertSee('Risk 91/100')
            ->assertSee('Under review')
            ->assertSee('The full balance was redeemed as a round number.')
            ->assertSee('Round number')
            ->assertSee('Historical demo data');
    }
}
