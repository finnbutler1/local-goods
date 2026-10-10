<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_wallet_links_to_admin_and_home(): void
    {
        $this->get('/demo/employee')
            ->assertOk()
            ->assertSee('See the BID admin view')
            ->assertSee('href="'.route('demo.bid-admin').'"', false)
            ->assertSee('href="'.route('home').'"', false)
            ->assertSee('aria-current="page"', false);
    }

    public function test_admin_links_to_wallet_and_home(): void
    {
        $this->get('/demo/bid-admin')
            ->assertOk()
            ->assertSee('See the employee wallet')
            ->assertSee('href="'.route('demo.employee').'"', false)
            ->assertSee('href="'.route('home').'"', false)
            ->assertSee('aria-current="page"', false);
    }
}
