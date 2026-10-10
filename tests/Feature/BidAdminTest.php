<?php

namespace Tests\Feature;

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
        $this->post('/demo/employee/redeem', ['merchant_id' => $deli->id, 'amount' => '20']);

        $this->get('/demo/bid-admin')
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
