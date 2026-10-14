<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\AdjudicationCase;
use App\Models\AdjudicationSet;
use App\Models\User;
use App\Services\Adjudication\AdjudicationResults;
use App\Services\Adjudication\AdjudicationSetBuilder;
use App\Services\Fraud\FraudEvalReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** What blind adjudicators said, on the admin page and /eval (ADR 0011). */
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
     * A reviewer who answers the opening question and votes on every case.
     * $side: 'ai' or 'finn' on disputed cases; controls get the shared label
     * unless $controlsWrong.
     */
    private function adjudicator(string $side, bool $seen = false, bool $submit = true, bool $controlsWrong = false, string $name = 'Synthetic Adjudicator'): User
    {
        $user = User::factory()->role(Role::Judge)->create(['name' => $name, 'reviewer_type' => ReviewerType::Bid]);
        $this->actingAs($user);
        $this->post('/adjudicate/start', ['seen_eval_before' => $seen ? 'yes' : 'no']);

        foreach ($this->set->cases as $case) {
            $label = match (true) {
                $case->isDisputed() => $side === 'ai' ? $case->ai_label : $case->my_label,
                $controlsWrong => $case->my_label === 'normal' ? 'suspicious' : 'normal',
                default => $case->my_label,
            };
            $this->post("/adjudicate/cases/{$case->id}", ['label' => $label, 'reason' => "Reason from {$name}."]);
        }

        if ($submit) {
            $this->post('/adjudicate/submit')->assertRedirect();
        }

        auth()->logout();

        return $user;
    }

    private function results(): ?array
    {
        return app(AdjudicationResults::class)->for($this->set);
    }

    public function test_nothing_until_someone_submits(): void
    {
        $this->adjudicator('ai', submit: false);

        $this->assertNull($this->results());
    }

    public function test_the_majority_decides_and_a_tie_is_split(): void
    {
        $this->adjudicator('ai');
        $this->adjudicator('finn');

        $all = $this->results()['all'];
        $this->assertSame(['cases' => $this->disputed, 'ai' => 0, 'finn' => 0, 'split' => $this->disputed], $all['summary']);

        $this->adjudicator('ai');
        $all = $this->results()['all'];
        $this->assertSame($this->disputed, $all['summary']['ai']);
        $this->assertSame(
            "On {$this->disputed} disputed cases, adjudicators sided with the AI in {$this->disputed}, with Finn in 0, split in 0.",
            $all['sentence'],
        );
    }

    public function test_results_are_also_given_for_only_those_who_had_not_seen_the_eval_page(): void
    {
        $this->adjudicator('finn', seen: false);
        $this->adjudicator('ai', seen: true);
        $this->adjudicator('ai', seen: true);

        $results = $this->results();
        $this->assertSame($this->disputed, $results['all']['summary']['ai']);
        $this->assertCount(3, $results['all']['adjudicators']);
        $this->assertSame($this->disputed, $results['unseen']['summary']['finn']);
        $this->assertCount(1, $results['unseen']['adjudicators']);
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
        $this->adjudicator('finn');
        $all = $this->results()['all'];

        $this->assertSame(['dev', 'holdout', 'holdout_2', 'holdout_3'], array_keys($all['by_split']));
        $this->assertSame($this->disputed, array_sum(array_column($all['by_split'], 'cases')));
        $this->assertSame($this->disputed, array_sum(array_column($all['by_split'], 'finn')));
        $this->assertNotEmpty($all['by_tag']);

        $tagged = AdjudicationCase::where('kind', 'disputed')->get()->filter(fn ($c) => in_array('round_amount', $c->tags, true))->count();
        $this->assertSame($tagged, $all['by_tag']['round_amount']['cases'] ?? 0);
    }

    public function test_unsubmitted_votes_never_count(): void
    {
        $this->adjudicator('ai');
        $this->adjudicator('finn', submit: false);
        $this->adjudicator('finn', submit: false);

        $this->assertSame($this->disputed, $this->results()['all']['summary']['ai']);
        $this->assertCount(1, $this->results()['all']['adjudicators']);
    }

    public function test_the_eval_page_shows_the_summary_after_the_first_submission_and_votes_only_after_close(): void
    {
        // Before anyone submits: only a placeholder that the judge home page links to.
        $this->get('/eval')->assertOk()
            ->assertSee('Results appear here once the first adjudicator submits.')
            ->assertDontSee('adjudicators sided with the AI');

        $this->adjudicator('finn', name: 'Pat Synthetic');

        $this->get('/eval')->assertOk()
            ->assertSee('Blind adjudication of the disagreements')
            ->assertSee("adjudicators sided with the AI in 0, with Finn in {$this->disputed}, split in 0.")
            ->assertSee('1 adjudicator voted')
            ->assertSee("Only adjudicators who hadn't looked at the eval page before")
            ->assertSee('appear here once voting closes')
            ->assertDontSee('Reason from Pat Synthetic.');

        app(AdjudicationSetBuilder::class)->close($this->set);

        $this->get('/eval')->assertOk()
            ->assertSee('Reason from Pat Synthetic.')
            ->assertSee('Adjudicator 1 (BID)')
            ->assertSee('saw eval before: no')
            ->assertDontSee('Pat Synthetic</span>', false)
            ->assertDontSee('Pat Synthetic (');
    }

    public function test_the_admin_page_shows_names_and_every_vote_while_voting_is_open(): void
    {
        $this->adjudicator('ai', seen: true, name: 'Pat Synthetic');

        $this->signIn(Role::PlatformAdmin);
        $this->get('/admin/adjudication')->assertOk()
            ->assertSee('Pat Synthetic (BID)')
            ->assertSee('saw eval before: yes')
            ->assertSee('Reason from Pat Synthetic.')
            ->assertSee("adjudicators sided with the AI in {$this->disputed}");
    }

    public function test_an_eligible_reviewer_who_has_not_submitted_sees_no_answers_on_the_eval_pages(): void
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

        $this->post('/adjudicate/start', ['seen_eval_before' => 'no']);
        foreach ($this->set->cases as $case) {
            $this->post("/adjudicate/cases/{$case->id}", ['label' => 'normal', 'reason' => 'Synthetic.']);
        }
        $this->post('/adjudicate/submit');

        $this->get('/eval')->assertOk()->assertDontSee('Hidden for now')->assertSee(e($firstDisagreement['explanation']), false);
    }

    public function test_guests_and_reviewers_who_cannot_vote_still_see_every_disagreement(): void
    {
        $explanation = e(collect(app(FraudEvalReport::class)->latest()['results'])->firstWhere('agree', false)['explanation']);

        $this->get('/eval')->assertOk()->assertDontSee('Hidden for now')->assertSee($explanation, false);

        $this->actingAs(User::factory()->role(Role::Judge)->create(['reviewer_type' => ReviewerType::Public]));
        $this->get('/eval')->assertOk()->assertDontSee('Hidden for now')->assertSee($explanation, false);
    }

    public function test_adjudication_never_changes_the_headline(): void
    {
        $before = app(FraudEvalReport::class)->latest()['headline'];
        $this->adjudicator('finn');
        app(AdjudicationSetBuilder::class)->close($this->set);

        $this->assertSame($before, app(FraudEvalReport::class)->latest()['headline']);
        $this->get('/eval')->assertOk()->assertSee($before['text']);
    }
}
