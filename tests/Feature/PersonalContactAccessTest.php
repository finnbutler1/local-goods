<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Http\Middleware\KeepPersonalContactsToAdjudication;
use App\Models\User;
use App\Services\Adjudication\AdjudicationSetBuilder;
use App\Services\ReviewerLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\SweepsPages;
use Tests\TestCase;

/**
 * Personal contacts (ADR 0019) get the blind adjudication and the feedback
 * widget, nothing else: no judge home, tour, view-as, eval or admin pages.
 * Walks the route list, so a page added later is covered.
 */
class PersonalContactAccessTest extends TestCase
{
    use RefreshDatabase, SweepsPages;

    private User $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $builder = app(AdjudicationSetBuilder::class);
        $builder->publish($builder->newDraft());

        $this->contact = User::factory()->role(Role::Judge)->create([
            'name' => 'Riley Fakename', 'email' => 'riley.fake@example.test',
            'reviewer_type' => ReviewerType::PersonalContact,
        ]);
    }

    public function test_only_the_voting_account_and_link_pages_open_every_other_page_is_refused(): void
    {
        $opened = [];

        foreach ($this->pages() as $uri) {
            $route = collect(Route::getRoutes()->getRoutes())->first(fn (RoutingRoute $r) => '/'.ltrim($r->uri(), '/') === $uri);
            $response = $this->actingAs($this->contact)->get($uri);

            if (in_array($route?->getName(), KeepPersonalContactsToAdjudication::SENT_TO_VOTING, true)) {
                $response->assertRedirect(route('adjudication.show'));
            } elseif ($response->isOk()) {
                $opened[] = $route?->getName();
            } elseif (! in_array($route?->getName(), KeepPersonalContactsToAdjudication::ALLOWED_ROUTES, true)) {
                $this->assertContains($response->status(), [403, 404], "{$uri} should be refused for a personal contact.");
            }
        }

        sort($opened);
        // judge-access.show is the magic link page itself.
        $this->assertSame(['account', 'adjudication.show', 'judge-access.show'], $opened);
    }

    public function test_the_named_pages_are_refused(): void
    {
        $this->actingAs($this->contact);

        foreach (['/eval', '/eval/merchants', '/bid-admin', '/bid-admin/eval', '/admin', '/admin/adjudication', '/employer', '/judge/view-as/employee'] as $uri) {
            $this->get($uri)->assertForbidden();
        }

        $this->get('/judge')->assertRedirect('/adjudicate');
        $this->get('/dashboard')->assertRedirect(route('judge.dashboard'));
        $this->get('/')->assertRedirect('/adjudicate');
        $this->get('/adjudicate')->assertOk()->assertSee('Check the AI')
            ->assertDontSee('View as')->assertDontSee(route('eval'), false);
    }

    public function test_feedback_still_works(): void
    {
        $this->actingAs($this->contact)->post('/feedback', [
            'page_url' => url('/adjudicate'), 'category' => 'functionality', 'rating' => 4,
            'severity' => 'nice_to_have', 'comment' => 'Synthetic: clear cases.',
        ])->assertRedirect();

        $this->assertSame(1, $this->contact->feedback()->count());
    }

    public function test_the_magic_link_lands_on_the_voting_page(): void
    {
        $link = app(ReviewerLinks::class)->newLink($this->contact);

        $this->post('/judge-access', ['link' => Str::after($link, '#')])->assertRedirect(route('adjudication.show'));
        $this->assertAuthenticatedAs($this->contact);
    }

    public function test_other_reviewers_are_not_restricted(): void
    {
        foreach ([ReviewerType::Friend, ReviewerType::Bid, ReviewerType::Public] as $type) {
            $this->actingAs(User::factory()->role(Role::Judge)->create(['reviewer_type' => $type]));
            $this->get('/judge')->assertOk();
            $this->get('/eval')->assertOk();
        }
    }
}
