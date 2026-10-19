<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Bid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SweepsPages;
use Tests\Support\AccessibilityAudit;
use Tests\TestCase;

/**
 * With nothing in the database (no employer, merchants, redemptions or eval
 * activity), every page still opens, says what will appear there, and passes
 * the static accessibility checks. A page added later is covered too.
 */
class EmptyStatesTest extends TestCase
{
    use RefreshDatabase;
    use SweepsPages;

    protected function setUp(): void
    {
        parent::setUp();
        // Local Goods always has its one BID (CLAUDE.md: one BID, no tenant switching); nothing else exists.
        Bid::create(['name' => 'Golden Triangle', 'city' => 'Washington, DC']);
    }

    public function test_every_page_opens_on_an_empty_database_for_every_role(): void
    {
        $found = [];
        $checked = $this->sweepPages(function (string $html, string $where) use (&$found) {
            if ($problems = AccessibilityAudit::problems($html)) {
                $found[$where] = $problems;
            }
        });

        // Pages that don't open (a role without access, or no wallet linked yet) must
        // fail politely, never with a server error.
        foreach (Role::cases() as $role) {
            $user = $this->signIn($role);
            foreach ($this->pages() as $uri) {
                $status = $this->actingAs($user)->get($uri)->getStatusCode();
                $this->assertLessThan(500, $status, "{$uri} as {$role->value} gave {$status} on an empty database.");
            }
        }

        $this->assertGreaterThan(20, $checked);
        $this->assertSame([], $found, print_r($found, true));
    }

    public function test_the_main_lists_say_what_will_appear_there(): void
    {
        $this->actingAs($this->signIn(Role::BidAdmin));
        $this->get('/bid-admin')->assertOk()->assertSee('No redemptions yet.');
        $this->get('/bid-admin/impact')->assertOk()->assertSee('No cleared redemptions yet');

        $this->actingAs($this->signIn(Role::EmployerAdmin));
        $this->get('/employer')->assertOk()->assertSee('No employer program is set up yet');

        $this->actingAs($this->signIn(Role::PlatformAdmin));
        $this->get('/admin/feedback')->assertOk();
        $this->get('/admin/reviewers')->assertOk()->assertSee('No reviewers yet.');
    }
}
