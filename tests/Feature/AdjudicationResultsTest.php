<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\AdjudicationCase;
use App\Models\AdjudicationSet;
use App\Models\AdjudicationVote;
use App\Models\User;
use App\Services\Adjudication\AdjudicationResults;
use App\Services\Adjudication\AdjudicationSetBuilder;
use App\Services\Fraud\FraudEvalReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What blind adjudicators said, on the admin page and /eval (ADR 0011;
 * personal contacts apart, ADR 0019; batches of 10, ADR 0020).
 */
class AdjudicationResultsTest extends TestCase
{
    use RefreshDatabase;

    private AdjudicationSet $set;

    private int $disputed;

    protected function setUp(): void
    {
        parent::setUp();
        $builder = app(AdjudicationSetBuilder::class);
        $this->set = $builder->newDraft();
        $builder->publish($this->set);
        $this->disputed = $this->set->cases()->where('kind', 'disputed')->count();
    }

    /**
     * A reviewer who answers the opening question and votes batch by batch.
     * $side: 'ai' or 'finn' on disputed cases; controls get the shared label
     * unless $controlsWrong. $batches: how many batches to submit (null: all
     * of them). $submit false: votes on the first batch but never submits it.
     */
    private function adjudicator(string $side, bool $seen = false, bool $submit = true, bool $controlsWrong = false, string $name = 'Synthetic Adjudicator', ReviewerType $type = ReviewerType::Bid, ?int $batches = null): User
    {
        $user = User::factory()->role(Role::Judge)->create(['name' => $name, 'reviewer_type' => $type]);
        $this->actingAs($user);
        $this->post('/adjudicate/start', ['seen_eval_before' => $seen ? 'yes' : 'no']);

        for ($done = 0; $batches === null || $done < $batches; $done++) {
            foreach ($this->set->participantFor($user)->openBatch()->pluck('adjudicationCase') as $case) {
                $label = match (true) {
                    $case->isDisputed() => $side === 'ai' ? $case->ai_label : $case->my_label,
                    $controlsWrong => $case->my_label === 'normal' ? 'suspicious' : 'normal',
                    default => $case->my_label,
                };
                $this->post("/adjudicate/cases/{$case->id}", ['label' => $label, 'reason' => "Reason from {$name}."]);
            }

            if (! $submit) {
                break;
            }
            $this->post('/adjudicate/submit')->assertRedirect();
            if ($this->set->hasSubmitted($user)) {
                break;
            }
            $this->post('/adjudicate/more')->assertRedirect();
        }

        auth()->logout();

        return $user;
    }

    private function results(): ?array
    {
        return app(AdjudicationResults::class)->for($this->set);
    }

    public function test_nothing_until_someone_submits_a_batch(): void
    {
        $this->adjudicator('ai', submit: false);

        $this->assertNull($this->results());
    }

    public function test_the_majority_decides_a_tie_is_split_and_each_figure_has_its_votes(): void
    {
        foreach (['ai', 'finn', 'ai', 'finn'] as $side) {
            $this->adjudicator($side);
        }

        $summary = $this->results()['all']['summary'];
        $this->assertSame($this->disputed, $summary['split']);
        $this->assertSame(4 * $this->disputed, $summary['split_votes']);

        $this->adjudicator('ai');
        $all = $this->results()['all'];
        $votes = 5 * $this->disputed;
        $this->assertSame($this->disputed, $all['summary']['ai']);
        $this->assertSame($votes, $all['summary']['votes']);
        $this->assertSame($votes, $all['summary']['ai_votes']);
        $this->assertSame(
            "On {$this->disputed} disputed cases ({$votes} votes), adjudicators sided with the AI in {$this->disputed} ({$votes} votes), "
            .'with Finn in 0 (0 votes), split in 0 (0 votes); 0 had too few votes to call (fewer than 3).',
            $all['sentence'],
        );
        $this->assertSame([5], array_values(array_unique(array_column($all['cases'], 'vote_count'))));
    }

    public function test_a_case_with_fewer_than_3_votes_is_too_few_to_call(): void
    {
        $this->adjudicator('ai');
        $this->adjudicator('ai');

        $all = $this->results()['all'];
        $this->assertSame($this->disputed, $all['summary']['too_few']);
        $this->assertSame(0, $all['summary']['ai']);
        $this->assertSame(['too_few'], array_values(array_unique(array_column($all['cases'], 'sided_with'))));

        $this->adjudicator('ai');
        $this->assertSame($this->disputed, $this->results()['all']['summary']['ai']);
        $this->assertSame(0, $this->results()['all']['summary']['too_few']);
    }

    public function test_a_voter_who_stops_after_one_batch_still_counts(): void
    {
        $user = $this->adjudicator('finn', batches: 1);

        $all = $this->results()['all'];
        $this->assertCount(1, $all['adjudicators']);
        $this->assertSame(10, $all['adjudicators'][0]['votes']);
        $this->assertSame(7, $all['summary']['votes'], 'Their 7 disputed votes count.');
        $this->assertSame(3, $all['controls']['of'], 'Their 3 control votes count.');
        $this->assertSame(7, collect($all['cases'])->where('vote_count', 1)->count());
        $this->assertFalse($this->set->hasSubmitted($user), 'They did not say they were done, and still count.');
        $this->assertSame(1, $this->set->submittedCount());
    }

    public function test_votes_in_an_unsubmitted_batch_never_count(): void
    {
        $user = $this->adjudicator('ai', batches: 1);
        // A second batch, voted on but not submitted.
        $this->actingAs($user);
        $this->post('/adjudicate/more');
        foreach ($this->set->participantFor($user)->openBatch()->pluck('adjudicationCase') as $case) {
            $this->post("/adjudicate/cases/{$case->id}", ['label' => 'normal', 'reason' => 'Synthetic.']);
        }
        $this->adjudicator('finn', submit: false);

        $all = $this->results()['all'];
        $this->assertCount(1, $all['adjudicators']);
        $this->assertSame(10, $all['adjudicators'][0]['votes']);
        $this->assertSame(20, AdjudicationVote::where('user_id', $user->id)->count());
    }

    public function test_votes_from_before_batches_still_count_for_whoever_pressed_submit_all(): void
    {
        $user = User::factory()->role(Role::Judge)->create(['reviewer_type' => ReviewerType::Bid]);
        $participant = $this->set->participants()->create(['user_id' => $user->id, 'seen_eval_before' => false]);
        foreach ($this->set->cases as $case) {
            AdjudicationVote::create(['adjudication_case_id' => $case->id, 'user_id' => $user->id, 'label' => $case->my_label, 'reason' => 'Synthetic.']);
        }
        $participant->forceFill(['submitted_at' => now()])->save();

        $all = $this->results()['all'];
        $this->assertSame($this->set->cases()->count(), $all['adjudicators'][0]['votes']);
        $this->assertSame($this->disputed, $all['summary']['votes']);
        $this->assertSame(1, $this->set->submittedCount());
    }

    public function test_results_are_also_given_for_only_those_who_had_not_seen_the_eval_page(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->adjudicator('finn', seen: false);
        }
        foreach (range(1, 4) as $ignored) {
            $this->adjudicator('ai', seen: true);
        }

        $results = $this->results();
        $this->assertSame($this->disputed, $results['all']['summary']['ai']);
        $this->assertCount(7, $results['all']['adjudicators']);
        $this->assertSame($this->disputed, $results['unseen']['summary']['finn']);
        $this->assertCount(3, $results['unseen']['adjudicators']);
    }

    public function test_control_cases_measure_how_often_adjudicators_agree_with_the_shared_label(): void
    {
        $controls = $this->set->cases()->where('kind', 'control')->count();
        $this->adjudicator('ai');
        $this->adjudicator('ai', controlsWrong: true);

        $c = $this->results()['all']['controls'];
        $this->assertSame($controls, $c['hits']);
        $this->assertSame(2 * $controls, $c['of']);
        $this->assertSame(50.0, $c['pct']);
        $this->assertNotNull($c['ci95']);
    }

    public function test_breakdowns_by_split_and_pattern_tag(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->adjudicator('finn');
        }
        $all = $this->results()['all'];

        $this->assertSame(['dev', 'holdout', 'holdout_2', 'holdout_3'], array_keys($all['by_split']));
        $this->assertSame($this->disputed, array_sum(array_column($all['by_split'], 'cases')));
        $this->assertSame($this->disputed, array_sum(array_column($all['by_split'], 'finn')));
        $this->assertSame(3 * $this->disputed, array_sum(array_column($all['by_split'], 'finn_votes')));
        $this->assertNotEmpty($all['by_tag']);

        $tagged = AdjudicationCase::where('kind', 'disputed')->get()->filter(fn ($c) => in_array('round_amount', $c->tags, true))->count();
        $this->assertSame($tagged, $all['by_tag']['round_amount']['cases'] ?? 0);
    }

    public function test_the_eval_page_shows_the_summary_after_the_first_submission_and_votes_only_after_close(): void
    {
        // Before anyone submits: only a placeholder that the judge home page links to.
        $this->get('/eval')->assertOk()
            ->assertSee('Results appear here once the first adjudicator submits.')
            ->assertDontSee('adjudicators sided with the AI');

        $this->adjudicator('finn', name: 'Pat Synthetic');
        $this->adjudicator('finn');
        $this->adjudicator('finn');
        $votes = 3 * $this->disputed;

        $this->get('/eval')->assertOk()
            ->assertSee('Blind adjudication of the disagreements')
            ->assertSee("adjudicators sided with the AI in 0 (0 votes), with Finn in {$this->disputed} ({$votes} votes)")
            ->assertSee('3 adjudicators voted')
            ->assertSee("Only adjudicators who hadn't looked at the eval page before")
            ->assertSee('appear here once voting closes')
            ->assertDontSee('Reason from Pat Synthetic.');

        app(AdjudicationSetBuilder::class)->close($this->set);

        $this->get('/eval')->assertOk()
            ->assertSee('Reason from Pat Synthetic.')
            ->assertSee('Adjudicator 1 (BID)')
            ->assertSee('saw eval before: no')
            ->assertSee('3 votes')
            ->assertDontSee('Pat Synthetic</span>', false)
            ->assertDontSee('Pat Synthetic (');
    }

    public function test_the_admin_page_shows_names_every_vote_and_the_too_few_flag(): void
    {
        $this->adjudicator('ai', seen: true, name: 'Pat Synthetic');

        $this->signIn(Role::PlatformAdmin);
        $this->get('/admin/adjudication')->assertOk()
            ->assertSee('Pat Synthetic (BID)')
            ->assertSee('saw eval before: yes')
            ->assertSee('Reason from Pat Synthetic.')
            ->assertSee("{$this->disputed} had too few votes to call")
            ->assertSee('Too few votes to call')
            ->assertSee('1 vote');
    }

    public function test_an_eligible_reviewer_who_has_not_finished_sees_no_answers_on_the_eval_pages(): void
    {
        $this->adjudicator('ai');
        $firstDisagreement = collect(app(FraudEvalReport::class)->latest()['results'])->firstWhere('agree', false);

        $user = User::factory()->role(Role::Judge)->create(['reviewer_type' => ReviewerType::ContestJudge]);
        $this->actingAs($user);

        foreach (['/eval', '/bid-admin/eval'] as $url) {
            $this->get($url)->assertOk()
                ->assertSee('Hidden for now')
                ->assertDontSee(e($firstDisagreement['explanation']), false)
                ->assertDontSee('Blind adjudication of the disagreements')
                ->assertSee('Caught 17 of 26 suspicious cases');
        }

        // After one batch they might do more, so the answers stay hidden until they say they're done.
        $this->post('/adjudicate/start', ['seen_eval_before' => 'no']);
        foreach ($this->set->participantFor($user)->openBatch()->pluck('adjudicationCase') as $case) {
            $this->post("/adjudicate/cases/{$case->id}", ['label' => 'normal', 'reason' => 'Synthetic.']);
        }
        $this->post('/adjudicate/submit');
        $this->get('/eval')->assertOk()->assertSee('Hidden for now');

        $this->post('/adjudicate/finish');
        $this->get('/eval')->assertOk()->assertDontSee('Hidden for now')->assertSee(e($firstDisagreement['explanation']), false);
    }

    public function test_guests_and_reviewers_who_cannot_vote_still_see_every_disagreement(): void
    {
        $explanation = e(collect(app(FraudEvalReport::class)->latest()['results'])->firstWhere('agree', false)['explanation']);

        $this->get('/eval')->assertOk()->assertDontSee('Hidden for now')->assertSee($explanation, false);

        $this->actingAs(User::factory()->role(Role::Judge)->create(['reviewer_type' => ReviewerType::Public]));
        $this->get('/eval')->assertOk()->assertDontSee('Hidden for now')->assertSee($explanation, false);
    }

    public function test_personal_contact_votes_never_change_the_headline_result(): void
    {
        $this->adjudicator('finn', name: 'Pat Synthetic');
        $this->adjudicator('finn', batches: 1);
        $this->adjudicator('ai', seen: true, type: ReviewerType::ContestJudge);
        $before = $this->results();
        $this->assertNull($before['personal']);

        // Personal contacts, all siding with the AI, would flip the headline if they were counted.
        $this->adjudicator('ai', name: 'Riley Fakename', type: ReviewerType::PersonalContact);
        $this->adjudicator('ai', name: 'Morgan Notreal', type: ReviewerType::PersonalContact);
        $this->adjudicator('ai', seen: true, controlsWrong: true, name: 'Casey Example', type: ReviewerType::PersonalContact);
        $this->adjudicator('ai', name: 'Jamie Placeholder', type: ReviewerType::PersonalContact, batches: 1);
        $after = $this->results();

        $this->assertEquals($before['all'], $after['all']);
        $this->assertEquals($before['unseen'], $after['unseen']);

        $personal = $after['personal'];
        $this->assertCount(4, $personal['all']['adjudicators']);
        $this->assertCount(3, $personal['unseen']['adjudicators']);
        $this->assertSame($this->disputed, $personal['all']['summary']['ai']);
        $this->assertSame(3 * $this->disputed + 7, $personal['all']['summary']['votes']);
        $this->assertStringStartsWith("On {$this->disputed} disputed cases (".(3 * $this->disputed + 7).' votes), personal contacts sided with the AI', $personal['all']['sentence']);
        $this->assertLessThan($personal['all']['controls']['of'], $personal['all']['controls']['hits']);
        $this->assertSame([1, 2, 3, 4], array_column($personal['all']['adjudicators'], 'number'));
        $this->assertSame(['R.F.', 'M.N.', 'C.E.', 'J.P.'], array_column($personal['all']['adjudicators'], 'name'));

        // Their per-case counts are their own: the headline's are unchanged.
        $this->assertSame(array_column($before['all']['cases'], 'vote_count'), array_column($after['all']['cases'], 'vote_count'));

        // The judge home card counts only the headline group.
        $this->assertSame(3, $this->set->submittedCount());
        $this->assertSame(4, $this->set->submittedCountForPersonalContacts());
    }

    public function test_personal_contacts_have_their_own_labeled_section_on_eval_and_admin(): void
    {
        $this->adjudicator('ai', name: 'Riley Fakename', type: ReviewerType::PersonalContact);

        // Only a personal contact has submitted: the headline says so and stays empty.
        $this->get('/eval')->assertOk()
            ->assertSee('No BID staff or contest judges have submitted yet.')
            ->assertSee('0 adjudicators voted')
            ->assertDontSee('adjudicators sided with the AI')
            ->assertSee('Personal contacts (know Finn): not counted in the result above')
            ->assertSee('personal contacts sided with the AI in 0')
            ->assertDontSee('Riley')->assertDontSee('R.F.');

        foreach (range(1, 3) as $ignored) {
            $this->adjudicator('finn', name: 'Pat Synthetic');
        }
        app(AdjudicationSetBuilder::class)->close($this->set);

        $this->get('/eval')->assertOk()
            ->assertSeeInOrder(["adjudicators sided with the AI in 0 (0 votes), with Finn in {$this->disputed}", 'Personal contacts (know Finn): not counted in the result above', "{$this->disputed} had too few votes to call"])
            ->assertSee('Contact 1')
            ->assertSee('Reason from Riley Fakename.')
            ->assertDontSee('Riley Fakename</span>', false);

        $this->signIn(Role::PlatformAdmin);
        $this->get('/admin/adjudication')->assertOk()
            ->assertSee('Personal contacts (know Finn): not counted in the result above')
            ->assertSee('Personal contacts (know Finn), not counted in the headline (1)')
            ->assertSee('BID and contest judges (3)')
            ->assertSee('R.F.')
            ->assertSee('Pat Synthetic (BID)')
            ->assertDontSee('Riley Fakename</', false)
            ->assertDontSee('Riley Fakename (', false);
    }

    public function test_adjudication_never_changes_the_headline(): void
    {
        $before = app(FraudEvalReport::class)->latest()['headline'];
        $this->adjudicator('finn');
        $this->adjudicator('ai', type: ReviewerType::PersonalContact);
        app(AdjudicationSetBuilder::class)->close($this->set);

        $this->assertSame($before, app(FraudEvalReport::class)->latest()['headline']);
        $this->get('/eval')->assertOk()->assertSee($before['text']);
    }
}
