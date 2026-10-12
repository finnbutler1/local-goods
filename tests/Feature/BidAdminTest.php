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

    public function test_cards_label_the_ai_call_and_the_final_call_separately(): void
    {
        $this->seed(DatabaseSeeder::class);
        $deli = Merchant::where('name', 'Press Room Deli')->firstOrFail();
        $this->signIn(Role::Employee);

        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(12, 'clear'));
        $this->post('/employee/redeem', ['merchant_id' => $deli->id, 'amount' => '4']);
        $this->app->instance(ClaudeClient::class, FakeClaudeClient::verdict(82, 'hold', 'Odd.'));
        $this->post('/employee/redeem', ['merchant_id' => $deli->id, 'amount' => '5']);
        $this->app->instance(ClaudeClient::class, new FakeClaudeClient(new \RuntimeException('API down')));
        $this->post('/employee/redeem', ['merchant_id' => $deli->id, 'amount' => '6']);

        $this->signIn(Role::BidAdmin);
        $this->get('/bid-admin')
            ->assertSee('AI decision:')
            ->assertSee('clear (risk 12)')
            ->assertSee('hold (risk 82)')
            ->assertSee('automatic hold (the AI check did not complete)')
            ->assertSee('approved automatically: the AI cleared it')
            ->assertSee('waiting for a BID admin');
    }
}
