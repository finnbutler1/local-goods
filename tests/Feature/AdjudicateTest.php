<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\AdjudicationSet;
use App\Models\AdjudicationVote;
use App\Models\User;
use App\Services\Adjudication\AdjudicationSetBuilder;
use App\Services\Fraud\FraudEvalReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/** Reviewers voting, blind, on the published adjudication set (ADR 0011). */
class AdjudicateTest extends TestCase
{
    use RefreshDatabase;

    private AdjudicationSet $set;

    protected function setUp(): void
    {
        parent::setUp();
        $builder = app(AdjudicationSetBuilder::class);
        $this->set = $builder->newDraft();
        $builder->publish($this->set);
    }

    private function reviewer(ReviewerType $type): User
    {
        return User::factory()->role(Role::Judge)->create(['reviewer_type' => $type]);
    }

    /** The cases in the batch this reviewer is working on, in their order. */
    private function batch(User $user): Collection
    {
        return $this->set->participantFor($user)->openBatch()->pluck('adjudicationCase');
    }

    private function voteOnBatch(User $user, string $label = 'suspicious'): void
    {
        foreach ($this->batch($user) as $case) {
            $this->post("/adjudicate/cases/{$case->id}", ['label' => $label, 'reason' => 'Synthetic reason.'])->assertRedirect();
        }
    }

    /** Batch after batch until they have seen every case. */
    private function voteOnEverything(User $user, string $label = 'suspicious'): void
    {
        while (true) {
            $this->voteOnBatch($user, $label);
            $this->post('/adjudicate/submit')->assertRedirect();
            if ($this->set->hasSubmitted($user)) {
                return;
            }
            $this->post('/adjudicate/more')->assertRedirect();
        }
    }

    public function test_a_bid_reviewer_answers_the_question_votes_on_a_batch_of_10_and_can_stop(): void
    {
        $user = $this->reviewer(ReviewerType::Bid);
        $this->actingAs($user);

        $this->get('/adjudicate')->assertOk()
            ->assertSee('Have you looked at the Local Goods eval results page before today?')
            ->assertSee('about 5 minutes')->assertDontSee('15 minutes');
        $this->post('/adjudicate/start', ['seen_eval_before' => 'no'])->assertRedirect('/adjudicate');

        $this->get('/adjudicate')->assertOk()->assertSee('1 of 10');

        $this->voteOnBatch($user);
        $this->assertSame(10, AdjudicationVote::where('user_id', $user->id)->count());

        $this->get('/adjudicate?n=11')->assertOk()->assertSee('Submit these 10');
        $this->post('/adjudicate/submit')->assertRedirect('/adjudicate');

        // Optional: more, or done.
        $this->assertFalse($this->set->hasSubmitted($user));
        $this->get('/adjudicate')->assertOk()->assertSee('Thanks! Want to do 10 more?')->assertSee("No thanks, I'm done", false);

        $this->post('/adjudicate/finish')->assertRedirect('/adjudicate');
        $this->assertTrue($this->set->hasSubmitted($user));
        $this->assertFalse($this->set->participantFor($user)->seen_eval_before);
        $this->get('/adjudicate')->assertOk()->assertSee('your votes are in')->assertSee('You voted on 10 of the 32 cases.');
        $this->post('/adjudicate/more')->assertForbidden();
    }

    public function test_each_batch_is_10_with_about_7_disputed_and_never_repeats_a_case(): void
    {
        $user = $this->reviewer(ReviewerType::Bid);
        $this->actingAs($user);
        $this->post('/adjudicate/start', ['seen_eval_before' => 'no']);
        $total = $this->set->cases()->count();

        $first = $this->batch($user);
        $this->assertCount(10, $first);
        $this->assertSame(7, $first->where('kind', 'disputed')->count());
        $this->assertSame(3, $first->where('kind', 'control')->count());

        $sizes = [];
        $seen = collect();
        while (true) {
            $batch = $this->batch($user);
            $sizes[] = $batch->count();
            $this->assertEmpty($seen->intersect($batch->pluck('id')), 'A case came up twice.');
            $seen = $seen->merge($batch->pluck('id'));
            $this->voteOnBatch($user);
            $this->post('/adjudicate/submit');
            if ($this->set->hasSubmitted($user)) {
                break;
            }
            $this->post('/adjudicate/more');
        }

        // 32 cases: 10, 10, 10, then the last 2, and then it stops.
        $this->assertSame([10, 10, 10, $total - 30], $sizes);
        $this->assertEqualsCanonicalizing($this->set->cases()->pluck('id')->all(), $seen->all());
        $this->get('/adjudicate')->assertOk()->assertSee("You've seen all {$total} cases", false);
        $this->post('/adjudicate/more')->assertForbidden();
    }

    public function test_the_order_is_shuffled_per_person(): void
    {
        $a = $this->reviewer(ReviewerType::Bid);
        $this->actingAs($a);
        $this->post('/adjudicate/start', ['seen_eval_before' => 'no']);
        $orderA = $this->batch($a)->pluck('id');

        $this->assertEquals($orderA, $this->batch($a)->pluck('id'), 'Stable across visits.');
        $this->assertNotEquals($orderA->sort()->values(), $orderA, 'Not in id order.');

        // Disputed and control cases are mixed, not one kind first (checked over several people).
        $grouped = 0;
        foreach (range(1, 5) as $ignored) {
            $user = $this->reviewer(ReviewerType::ContestJudge);
            $this->actingAs($user);
            $this->post('/adjudicate/start', ['seen_eval_before' => 'no']);
            $kinds = $this->batch($user)->pluck('kind');
            $grouped += (int) ($kinds->all() === $kinds->sort()->values()->all() || $kinds->all() === $kinds->sortDesc()->values()->all());
        }
        $this->assertLessThan(5, $grouped);
    }

    public function test_only_cases_in_the_current_batch_can_be_voted_on(): void
    {
        $user = $this->reviewer(ReviewerType::Bid);
        $this->actingAs($user);
        $this->post('/adjudicate/start', ['seen_eval_before' => 'no']);
        $outside = $this->set->cases()->whereNotIn('id', $this->batch($user)->pluck('id'))->firstOrFail();

        $this->post("/adjudicate/cases/{$outside->id}", ['label' => 'normal', 'reason' => 'x'])->assertForbidden();
        $this->post('/adjudicate/more')->assertForbidden();
        $this->post('/adjudicate/finish')->assertForbidden();
        $this->assertSame(0, AdjudicationVote::count());
    }

    public function test_a_contest_judge_can_vote_too(): void
    {
        $user = $this->reviewer(ReviewerType::ContestJudge);
        $this->actingAs($user);
        $this->post('/adjudicate/start', ['seen_eval_before' => 'yes'])->assertRedirect();

        $case = $this->batch($user)->first();
        $this->post("/adjudicate/cases/{$case->id}", ['label' => 'normal', 'reason' => 'Looks like lunch.'])->assertRedirect();
        $this->assertSame(1, AdjudicationVote::count());
    }

    public function test_the_opening_question_is_required_and_comes_first(): void
    {
        $this->actingAs($this->reviewer(ReviewerType::Bid));
        $case = $this->set->cases()->firstOrFail();

        $this->post('/adjudicate/start', [])->assertSessionHasErrors('seen_eval_before');
        $this->post('/adjudicate/start', ['seen_eval_before' => 'maybe'])->assertSessionHasErrors('seen_eval_before');

        $this->post("/adjudicate/cases/{$case->id}", ['label' => 'normal', 'reason' => 'x'])->assertSessionHasErrors('seen_eval_before');
        $this->assertSame(0, AdjudicationVote::count());
    }

    public function test_the_answer_can_change_until_submit_and_is_locked_after(): void
    {
        $user = $this->reviewer(ReviewerType::Bid);
        $this->actingAs($user);
        $this->post('/adjudicate/start', ['seen_eval_before' => 'yes']);
        $this->post('/adjudicate/start', ['seen_eval_before' => 'no']);
        $this->assertFalse($this->set->participantFor($user)->seen_eval_before);

        $this->voteOnEverything($user);
        $this->post('/adjudicate/submit');

        $this->post('/adjudicate/start', ['seen_eval_before' => 'yes'])->assertForbidden();
        $this->assertFalse($this->set->participantFor($user)->seen_eval_before);
    }

    public function test_a_reason_and_a_valid_label_are_required(): void
    {
        $user = $this->reviewer(ReviewerType::Bid);
        $this->actingAs($user);
        $this->post('/adjudicate/start', ['seen_eval_before' => 'no']);
        $case = $this->batch($user)->first();

        $this->post("/adjudicate/cases/{$case->id}", ['label' => 'suspicious', 'reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/adjudicate/cases/{$case->id}", ['label' => 'maybe', 'reason' => 'Hmm.'])->assertSessionHasErrors('label');
        $this->assertSame(0, AdjudicationVote::count());
    }

    public function test_one_vote_per_case_which_can_change_until_submit(): void
    {
        $user = $this->reviewer(ReviewerType::Bid);
        $this->actingAs($user);
        $this->post('/adjudicate/start', ['seen_eval_before' => 'no']);
        $case = $this->batch($user)->first();

        $this->post("/adjudicate/cases/{$case->id}", ['label' => 'suspicious', 'reason' => 'First thought.']);
        $this->post("/adjudicate/cases/{$case->id}", ['label' => 'normal', 'reason' => 'Second thought.']);

        $this->assertSame(1, AdjudicationVote::count());
        $this->assertSame('normal', AdjudicationVote::sole()->label);

        $this->voteOnBatch($user);
        $this->post('/adjudicate/submit');

        $this->post("/adjudicate/cases/{$case->id}", ['label' => 'suspicious', 'reason' => 'Too late.'])->assertForbidden();
        $this->assertSame('suspicious', AdjudicationVote::where('adjudication_case_id', $case->id)->value('label'));
    }

    public function test_submitting_a_batch_needs_every_case_in_it_answered(): void
    {
        $user = $this->reviewer(ReviewerType::Bid);
        $this->actingAs($user);
        $this->post('/adjudicate/start', ['seen_eval_before' => 'no']);
        $case = $this->batch($user)->first();
        $this->post("/adjudicate/cases/{$case->id}", ['label' => 'normal', 'reason' => 'Fine.']);

        $this->post('/adjudicate/submit')->assertSessionHasErrors('submit');
        $this->assertFalse($this->set->hasSubmitted($user));
        $this->assertSame(0, $this->set->participantFor($user)->assignments()->whereNotNull('submitted_at')->count());
    }

    public function test_a_case_from_another_set_is_refused(): void
    {
        $builder = app(AdjudicationSetBuilder::class);
        $next = $builder->newDraft();
        $draftCase = $next->cases()->firstOrFail();

        $this->actingAs($this->reviewer(ReviewerType::Bid));
        $this->post('/adjudicate/start', ['seen_eval_before' => 'no']);

        $this->post("/adjudicate/cases/{$draftCase->id}", ['label' => 'normal', 'reason' => 'x'])->assertNotFound();
    }

    public function test_voting_stops_when_the_set_closes(): void
    {
        $user = $this->reviewer(ReviewerType::Bid);
        $this->actingAs($user);
        $this->post('/adjudicate/start', ['seen_eval_before' => 'no']);
        app(AdjudicationSetBuilder::class)->close($this->set);

        $case = $this->set->cases()->firstOrFail();
        $this->post("/adjudicate/cases/{$case->id}", ['label' => 'normal', 'reason' => 'x'])->assertForbidden();
        $this->get('/adjudicate')->assertOk()->assertSee('Voting has closed');
    }

    public function test_a_personal_contact_answers_the_question_votes_once_per_case_and_submits(): void
    {
        $user = $this->reviewer(ReviewerType::PersonalContact);
        $this->actingAs($user);

        $this->get('/adjudicate')->assertOk()->assertSee('Have you looked at the Local Goods eval results page before today?');
        $this->post('/adjudicate/start', ['seen_eval_before' => 'yes'])->assertRedirect('/adjudicate');

        // Voting again on a case changes that vote; it never adds a second one.
        $case = $this->batch($user)->first();
        $this->post("/adjudicate/cases/{$case->id}", ['label' => 'normal', 'reason' => 'First thought.']);
        $this->post("/adjudicate/cases/{$case->id}", ['label' => 'suspicious', 'reason' => 'Second thought.']);
        $this->assertSame(1, AdjudicationVote::where('user_id', $user->id)->where('adjudication_case_id', $case->id)->count());
        $this->assertSame('suspicious', AdjudicationVote::where('user_id', $user->id)->value('label'));

        $this->voteOnEverything($user);

        $this->assertTrue($this->set->hasSubmitted($user));
        $this->assertTrue($this->set->participantFor($user)->seen_eval_before);
        $this->assertSame($this->set->cases()->count(), AdjudicationVote::where('user_id', $user->id)->count());
        $this->get('/adjudicate')->assertOk()->assertSee('your votes are in')->assertDontSee(route('eval'), false);
    }

    public function test_the_public_link_still_cannot_vote_and_friends_still_cannot_either(): void
    {
        $case = $this->set->cases()->firstOrFail();

        foreach ([ReviewerType::Public, ReviewerType::Friend] as $type) {
            $user = $this->reviewer($type);
            $this->assertFalse($user->canAdjudicate());
            $this->actingAs($user);
            $this->post('/adjudicate/start', ['seen_eval_before' => 'no'])->assertForbidden();
            $this->post("/adjudicate/cases/{$case->id}", ['label' => 'normal', 'reason' => 'x'])->assertForbidden();
        }

        $this->assertTrue($this->reviewer(ReviewerType::PersonalContact)->canAdjudicate());
        $this->assertSame(0, AdjudicationVote::count());
    }

    public function test_public_friend_and_other_reviewer_types_can_look_but_get_403_on_every_vote_route(): void
    {
        $case = $this->set->cases()->firstOrFail();

        foreach ([ReviewerType::Public, ReviewerType::Friend, ReviewerType::EmployerHr, ReviewerType::Merchant] as $type) {
            $this->actingAs($this->reviewer($type));

            $this->get('/adjudicate')->assertOk()->assertSee('You can look, but not vote.');
            $this->post('/adjudicate/start', ['seen_eval_before' => 'no'])->assertForbidden();
            $this->post("/adjudicate/cases/{$case->id}", ['label' => 'normal', 'reason' => 'x'])->assertForbidden();
            $this->post('/adjudicate/submit')->assertForbidden();
        }

        $this->assertSame(0, AdjudicationVote::count());
        $this->assertSame(0, $this->set->participants()->count());
    }

    public function test_staff_can_never_vote(): void
    {
        $case = $this->set->cases()->firstOrFail();

        foreach ([Role::PlatformAdmin, Role::BidAdmin, Role::EmployerAdmin, Role::Employee, Role::Merchant] as $role) {
            $this->signIn($role);
            $this->post("/adjudicate/cases/{$case->id}", ['label' => 'normal', 'reason' => 'x'])->assertForbidden();
            $this->post('/adjudicate/submit')->assertForbidden();
        }

        $this->assertSame(0, AdjudicationVote::count());
    }

    public function test_a_revoked_reviewer_is_signed_out_and_cannot_vote(): void
    {
        $user = $this->reviewer(ReviewerType::Bid);
        $this->actingAs($user);
        $user->forceFill(['revoked_at' => now()])->save();

        $case = $this->set->cases()->firstOrFail();
        $this->post("/adjudicate/cases/{$case->id}", ['label' => 'normal', 'reason' => 'x']);

        $this->assertGuest();
        $this->assertSame(0, AdjudicationVote::count());
    }

    public function test_the_page_shows_only_the_facts(): void
    {
        $other = $this->reviewer(ReviewerType::ContestJudge);
        $this->actingAs($other);
        $this->post('/adjudicate/start', ['seen_eval_before' => 'no']);
        $this->voteOnEverything($other);
        AdjudicationVote::query()->update(['reason' => 'OTHER-REVIEWER-REASON']);

        $user = $this->reviewer(ReviewerType::Bid);
        $this->actingAs($user);
        $this->post('/adjudicate/start', ['seen_eval_before' => 'no']);
        $results = collect(app(FraudEvalReport::class)->latest()['results'])->keyBy('case_id');

        $batch = $this->batch($user);
        foreach (range(1, $batch->count()) as $n) {
            $html = $this->get("/adjudicate?n={$n}")->assertOk()->getContent();
            $case = $batch[$n - 1];
            $r = $results[$case->case_id];

            $this->assertStringContainsString(e($case->facts['merchant']), $html);
            $this->assertStringContainsString(e($case->facts['recent_history']), $html);
            $this->assertStringNotContainsString($case->case_id, $html, 'The case id would let a reviewer look it up.');
            $this->assertStringNotContainsString(e($r['notes']), $html);
            $this->assertStringNotContainsString(e($r['explanation']), $html);
            $this->assertStringNotContainsString('risk', strtolower(strip_tags($html)));
            $this->assertStringNotContainsString('OTHER-REVIEWER-REASON', $html);
            foreach (['disputed', 'control', 'holdout', 'my_label', 'ai_label'] as $word) {
                $this->assertStringNotContainsString($word, strtolower(strip_tags($html)), "Redemption {$n} mentions {$word}.");
            }
        }
    }

    public function test_each_reviewer_gets_their_own_stable_order(): void
    {
        $a = $this->reviewer(ReviewerType::Bid);
        $b = $this->reviewer(ReviewerType::ContestJudge);

        $orderA = $this->set->casesInOrderFor($a)->pluck('case_id');
        $this->assertEquals($orderA, $this->set->casesInOrderFor($a)->pluck('case_id'));
        $this->assertNotEquals($orderA, $this->set->casesInOrderFor($b)->pluck('case_id'));
        $this->assertNotEquals($this->set->cases()->orderBy('id')->pluck('case_id'), $orderA, 'Not the order the set was built in.');
    }

    public function test_the_judge_landing_page_links_to_it_while_voting_is_open(): void
    {
        $user = $this->reviewer(ReviewerType::Bid);
        $this->actingAs($user);

        $this->get('/judge')->assertOk()->assertSee('Help check the AI, about 5 minutes')->assertSee(route('adjudication.show'));

        app(AdjudicationSetBuilder::class)->close($this->set);
        $this->get('/judge')->assertOk()->assertDontSee('Help check the AI');
    }

    public function test_reviewers_who_cannot_vote_see_the_independent_check_card_instead(): void
    {
        foreach ([ReviewerType::Public, ReviewerType::Friend] as $type) {
            $this->actingAs($this->reviewer($type));

            $this->get('/judge')->assertOk()
                ->assertSee('id="independent-check-heading"', false)
                ->assertSee('invited BID staff and contest judges rate each disputed case blind')
                ->assertSee('never my label or the AI\'s call', false)
                ->assertSee('hidden controls')
                ->assertSee('0 adjudicators have submitted so far.')
                ->assertSee('Voting closes Nov 13.')
                ->assertSee('Voting is limited to invited reviewers')
                ->assertSee(route('eval').'#adjudication')
                ->assertDontSee('Help check the AI');
        }
    }

    public function test_reviewers_who_can_vote_keep_the_help_check_card(): void
    {
        $this->actingAs($this->reviewer(ReviewerType::ContestJudge));

        $this->get('/judge')->assertOk()
            ->assertSee('Help check the AI, about 5 minutes')
            ->assertDontSee('id="independent-check-heading"', false);
    }

    public function test_the_independent_check_card_counts_submissions_and_shows_when_voting_ends(): void
    {
        $voter = $this->reviewer(ReviewerType::Bid);
        $this->actingAs($voter);
        $this->post('/adjudicate/start', ['seen_eval_before' => 'no']);
        $this->voteOnEverything($voter);

        // Someone who started but didn't submit doesn't count.
        $this->actingAs($this->reviewer(ReviewerType::ContestJudge));
        $this->post('/adjudicate/start', ['seen_eval_before' => 'yes']);

        $friend = $this->reviewer(ReviewerType::Friend);
        $this->actingAs($friend);
        $this->get('/judge')->assertOk()->assertSee('1 adjudicator has submitted so far.');

        config(['reviewers.adjudication_ends_at' => '2026-11-20 23:59']);
        $this->get('/judge')->assertSee('Voting closes Nov 20.');

        app(AdjudicationSetBuilder::class)->close($this->set);
        $this->get('/judge')->assertOk()->assertSee('Voting is closed.')->assertDontSee('Voting closes');
    }

    public function test_the_card_link_lands_on_the_adjudication_section_of_eval(): void
    {
        $this->actingAs($this->reviewer(ReviewerType::Public));

        // Before anyone submits, a short placeholder holds the anchor.
        $this->get('/eval')->assertOk()->assertSee('id="adjudication"', false)
            ->assertSee('Voting is open until Nov 13.');

        $voter = $this->reviewer(ReviewerType::Bid);
        $this->actingAs($voter);
        $this->post('/adjudicate/start', ['seen_eval_before' => 'no']);
        $this->voteOnEverything($voter);

        $this->actingAs($this->reviewer(ReviewerType::Public));
        $this->get('/eval')->assertOk()->assertSee('id="adjudication"', false)
            ->assertSee('adjudicators sided with the AI')
            ->assertDontSee('Results appear here once the first adjudicator submits');
    }

    public function test_the_tour_step_sends_voters_to_vote_and_everyone_else_to_the_results(): void
    {
        $this->actingAs($this->reviewer(ReviewerType::Bid));
        $this->get('/judge')->assertSeeInOrder(['AI eval results', route('adjudication.show'), 'Independent check of the AI', 'Employer dashboard'], false);

        $this->actingAs($this->reviewer(ReviewerType::Friend));
        $this->get('/judge')->assertSeeInOrder(['AI eval results', route('eval').'#adjudication', 'Independent check of the AI', 'Employer dashboard'], false);
    }
}
