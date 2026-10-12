<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardTest extends TestCase
{
    public function test_dashboard_renders_in_the_app_shell(): void
    {
        $response = $this->get('/dashboard')->assertOk();

        $response->assertSee('Alex Morgan (demo)')
            ->assertSee('Employer admin')
            ->assertSee('aria-label="App"', false)
            ->assertSee('href="#main"', false)
            ->assertSee('href="'.route('demo.employee').'"', false)
            ->assertSee('href="'.route('demo.bid-admin.eval').'"', false);

        // Both navs (sidebar and mobile tab bar) mark Dashboard as the current page.
        $this->assertSame(2, substr_count($response->getContent(), 'aria-current="page"'));
        $this->assertMatchesRegularExpression('/href="'.preg_quote(route('dashboard'), '/').'"\s+aria-current="page"/', $response->getContent());
    }
}
