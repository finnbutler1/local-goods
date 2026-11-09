<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Http\Controllers\JudgeController;
use App\Models\AdjudicationParticipant;
use App\Models\AdjudicationSet;
use App\Models\AdjudicationVote;
use App\Models\User;
use App\Services\Adjudication\AdjudicationSetBuilder;
use App\Services\Fraud\FraudEvalReport;
use App\Services\ReviewerLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The blind adjudication was retired with no votes (ADR 0041): closed, out of sight, data intact. */
class AdjudicationRetiredTest extends TestCase
{
    use RefreshDatabase;

    private const GONE = ['adjudicat', 'help check the ai', 'independent check', 'hidden for now', 'submit your votes'];

    private function reviewer(ReviewerType $type): User
    {
        return User::factory()->role(Role::Judge)->create(['reviewer_type' => $type]);
    }

    /** A set stored as published, as it is on live. */
    private function publishedSet(): AdjudicationSet
    {
        $builder = app(AdjudicationSetBuilder::class);
        $set = $builder->newDraft();
        $builder->publish($set);

        return $set->fresh();
    }

    private function assertNoTraceOfIt(string $html, string $where): void
    {
        foreach (self::GONE as $phrase) {
            $this->assertStringNotContainsStringIgnoringCase($phrase, $html, "{$where} still mentions \"{$phrase}\".");
        }
    }

    public function test_it_is_retired_and_a_stored_published_set_behaves_as_closed(): void
    {
        $set = $this->publishedSet();

        $this->assertTrue(config('reviewers.adjudication_retired'));
        $this->assertFalse($set->isOpen());
        $this->assertTrue($set->isClosed());
        $this->assertSame(AdjudicationSet::PUBLISHED, $set->fresh()->status, 'The stored row must not change.');
    }

    public function test_the_judge_home_and_eval_pages_no_longer_show_it(): void
    {
        $this->publishedSet();
        $this->assertNoTraceOfIt($this->get('/eval')->assertOk()->getContent(), '/eval signed out');

        foreach ([ReviewerType::Bid, ReviewerType::ContestJudge, ReviewerType::Public, ReviewerType::Friend] as $type) {
            $this->actingAs($this->reviewer($type));

            foreach (['/judge', '/eval', '/bid-admin/eval'] as $url) {
                $this->assertNoTraceOfIt($this->get($url)->assertOk()->getContent(), "{$url} as {$type->value}");
            }
        }
    }

    public function test_the_tour_has_no_step_for_it(): void
    {
        $this->assertNotContains('Independent check of the AI', array_column(JudgeController::tour(), 'title'));
    }

    public function test_people_who_could_have_voted_now_see_every_disagreement(): void
    {
        $this->publishedSet();
        $explanation = e(collect(app(FraudEvalReport::class)->latest()['results'])->firstWhere('agree', false)['explanation']);

        $this->actingAs($this->reviewer(ReviewerType::ContestJudge));

        $this->get('/eval')->assertOk()->assertSee($explanation, false);
    }

    public function test_an_old_voting_link_shows_the_closed_page_whatever_the_stored_state(): void
    {
        $types = [ReviewerType::Bid, ReviewerType::ContestJudge, ReviewerType::Public, ReviewerType::PersonalContact];
        $check = function () use ($types) {
            foreach ($types as $type) {
                $this->actingAs($this->reviewer($type));
                $this->get('/adjudicate?n=3')->assertOk()->assertSee('Voting has closed')->assertDontSee('name="label"', false);
            }
        };

        $check(); // No set at all.

        $set = $this->publishedSet();
        $check();

        config(['reviewers.adjudication_retired' => false]);
        app(AdjudicationSetBuilder::class)->close($set);
        config(['reviewers.adjudication_retired' => true]);
        $check();
    }

    public function test_a_personal_contacts_magic_link_lands_on_the_closed_page_with_no_way_to_eval(): void
    {
        $this->publishedSet();
        $contact = $this->reviewer(ReviewerType::PersonalContact);
        $link = app(ReviewerLinks::class)->newLink($contact);

        $this->post('/judge-access', ['link' => Str::after($link, '#')])->assertRedirect(route('adjudication.show'));

        $this->get('/adjudicate')->assertOk()->assertSee('Voting has closed')
            ->assertDontSee(route('eval'), false)->assertDontSee(route('judge.dashboard'), false);
        $this->get('/judge')->assertRedirect(route('adjudication.show'));
    }

    public function test_old_voting_forms_write_nothing_and_land_on_the_closed_page(): void
    {
        $set = $this->publishedSet();
        $case = $set->cases()->orderBy('id')->first();

        foreach ([ReviewerType::Bid, ReviewerType::PersonalContact] as $type) {
            $this->actingAs($this->reviewer($type));

            $this->post('/adjudicate/start', ['seen_eval_before' => 'no'])->assertRedirect(route('adjudication.show'));
            $this->post("/adjudicate/cases/{$case->id}", ['label' => 'normal', 'reason' => 'Synthetic.'])->assertRedirect(route('adjudication.show'));
            $this->post('/adjudicate/submit')->assertRedirect(route('adjudication.show'));
            $this->post('/adjudicate/more')->assertRedirect(route('adjudication.show'));
            $this->post('/adjudicate/finish')->assertRedirect(route('adjudication.show'));
        }

        $this->assertSame(0, AdjudicationVote::count());
        $this->assertSame(0, AdjudicationParticipant::count());
        $this->assertSame(AdjudicationSet::PUBLISHED, $set->fresh()->status);
    }

    public function test_the_admin_page_is_read_only_and_still_shows_stored_votes(): void
    {
        config(['reviewers.adjudication_retired' => false]);
        $set = $this->publishedSet();
        $voter = User::factory()->role(Role::Judge)->create(['name' => 'Pat Synthetic', 'reviewer_type' => ReviewerType::Bid]);
        $this->actingAs($voter)->post('/adjudicate/start', ['seen_eval_before' => 'no']);
        $batch = $set->participantFor($voter)->openBatch()->pluck('adjudicationCase');
        foreach ($batch as $case) {
            $this->post("/adjudicate/cases/{$case->id}", ['label' => 'normal', 'reason' => 'A stored synthetic reason.']);
        }
        $this->post('/adjudicate/submit')->assertRedirect();
        config(['reviewers.adjudication_retired' => true]);
        $votes = AdjudicationVote::count();

        $this->signIn(Role::PlatformAdmin);
        $this->get('/admin/adjudication')->assertOk()
            ->assertSee('Retired: voting has closed and this page is read-only.')
            ->assertSee('Pat Synthetic (BID)')
            ->assertSee('A stored synthetic reason.')
            ->assertDontSee('Close voting')->assertDontSee('Start version')->assertDontSee('<form method="POST" action="'.route('admin.adjudication.store'), false);

        $this->post('/admin/adjudication')->assertRedirect(route('admin.adjudication.index'));
        $this->post('/admin/adjudication/close')->assertRedirect(route('admin.adjudication.index'));
        $this->post('/admin/adjudication/publish')->assertRedirect(route('admin.adjudication.index'));
        $this->post('/admin/adjudication/cases', ['case_id' => 'X1'])->assertRedirect(route('admin.adjudication.index'));

        $this->assertSame($votes, AdjudicationVote::count());
        $this->assertSame(1, AdjudicationSet::count());
        $this->assertSame(AdjudicationSet::PUBLISHED, $set->fresh()->status);
    }

    public function test_a_draft_stays_visible_but_cannot_be_changed(): void
    {
        $draft = app(AdjudicationSetBuilder::class)->newDraft();
        $case = $draft->cases()->orderBy('id')->first();
        $count = $draft->cases()->count();

        $this->signIn(Role::PlatformAdmin);
        $this->get('/admin/adjudication')->assertOk()->assertSee($case->case_id)
            ->assertDontSee('Publish and lock')->assertDontSee(route('admin.adjudication.cases.destroy', $case), false);

        $this->delete(route('admin.adjudication.cases.destroy', $case))->assertRedirect(route('admin.adjudication.index'));
        $this->assertSame($count, $draft->cases()->count());
        $this->assertTrue($draft->fresh()->isDraft());
    }
}
