<?php

namespace Tests\Feature;

use Tests\TestCase;

class LandingPageTest extends TestCase
{
    public function test_landing_page_shows_headline_and_demo_links(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Spend local. Prove it.')
            ->assertSee("See an employee's wallet", false)
            ->assertSee('See the BID admin view')
            ->assertSee('All businesses, people, and transactions are fictional.')
            ->assertDontSee('public records');
    }
}
