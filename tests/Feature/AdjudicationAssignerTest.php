<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\AdjudicationAssignment;
use App\Models\AdjudicationParticipant;
use App\Models\AdjudicationSet;
use App\Models\AdjudicationVote;
use App\Models\User;
use App\Services\Adjudication\AdjudicationAssigner;
use App\Services\Adjudication\AdjudicationSetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Batches of 10, balanced within each voter group (ADR 0020). */
class AdjudicationAssignerTest extends TestCase
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

    private function participant(ReviewerType $type): AdjudicationParticipant
    {
        $user = User::factory()->role(Role::Judge)->create(['reviewer_type' => $type]);

        return $this->set->participants()->create(['user_id' => $user->id, 'seen_eval_before' => false]);
    }

    /** Votes each case has been given out for, in one group, by kind. */
    private function spread(bool $personal, string $kind): array
    {
        $ids = $this->set->participants()->with('user')->get()
            ->filter(fn ($p) => $p->user->isPersonalContact() === $personal)->pluck('id');
        $counts = AdjudicationAssignment::whereIn('adjudication_participant_id', $ids)->get()->countBy('adjudication_case_id');

        return $this->set->cases()->where('kind', $kind)->pluck('id')->map(fn ($id) => $counts[$id] ?? 0)->all();
    }

    public function test_one_batch_each_spreads_votes_evenly_across_cases_of_each_kind(): void
    {
        $assigner = app(AdjudicationAssigner::class);

        foreach (range(1, 7) as $i) {
            $assigner->nextBatch($this->participant($i % 2 ? ReviewerType::Bid : ReviewerType::ContestJudge));
        }

        // 7 people x 7 disputed = 49 over 16 disputed cases: every case 3 or 4.
        $disputed = $this->spread(false, 'disputed');
        $controls = $this->spread(false, 'control');
        $this->assertSame(49, array_sum($disputed));
        $this->assertLessThanOrEqual(1, max($disputed) - min($disputed), 'Disputed cases are unevenly covered.');
        $this->assertSame(21, array_sum($controls));
        $this->assertLessThanOrEqual(1, max($controls) - min($controls), 'Control cases are unevenly covered.');
    }

    public function test_balance_holds_when_some_people_do_more_than_one_batch(): void
    {
        $assigner = app(AdjudicationAssigner::class);
        $keen = $this->participant(ReviewerType::Bid);
        $assigner->nextBatch($keen);
        $assigner->nextBatch($keen);

        foreach (range(1, 4) as $ignored) {
            $assigner->nextBatch($this->participant(ReviewerType::ContestJudge));
        }

        $disputed = $this->spread(false, 'disputed');
        $this->assertLessThanOrEqual(1, max($disputed) - min($disputed));
    }

    public function test_personal_contacts_are_balanced_on_their_own(): void
    {
        $assigner = app(AdjudicationAssigner::class);
        foreach (range(1, 3) as $ignored) {
            $assigner->nextBatch($this->participant(ReviewerType::Bid));
        }

        // The judges' batches don't count towards the contacts' balance, and the reverse.
        $contact = $this->participant(ReviewerType::PersonalContact);
        $this->assertSame([], $assigner->load($contact)->all());

        foreach (range(1, 5) as $ignored) {
            $assigner->nextBatch($this->participant(ReviewerType::PersonalContact));
        }
        $assigner->nextBatch($contact);

        $disputed = $this->spread(true, 'disputed');
        $this->assertSame(42, array_sum($disputed));
        $this->assertLessThanOrEqual(1, max($disputed) - min($disputed));
        $this->assertSame(21, array_sum($this->spread(false, 'disputed')), 'The judges\' counts are unchanged.');
    }

    public function test_votes_from_before_batches_count_towards_the_balance(): void
    {
        // Someone who pressed Submit all on every case before batches existed.
        $before = $this->participant(ReviewerType::Bid);
        foreach ($this->set->cases as $case) {
            AdjudicationVote::create(['adjudication_case_id' => $case->id, 'user_id' => $before->user_id, 'label' => 'normal', 'reason' => 'Synthetic.']);
        }
        $before->forceFill(['submitted_at' => now()])->save();

        $next = $this->participant(ReviewerType::Bid);
        $load = app(AdjudicationAssigner::class)->load($next);

        $this->assertSame($this->set->cases()->count(), $load->count());
        $this->assertSame([1], $load->unique()->values()->all());
        $this->assertTrue($before->submittedBeforeBatches());
    }

    public function test_the_last_batch_takes_whatever_is_left(): void
    {
        $assigner = app(AdjudicationAssigner::class);
        $p = $this->participant(ReviewerType::Bid);

        $sizes = [];
        while (($batch = $assigner->nextBatch($p))->isNotEmpty()) {
            $sizes[] = $batch->count();
        }

        $this->assertSame([10, 10, 10, 2], $sizes);
        $this->assertSame($this->set->cases()->count(), $p->assignments()->distinct()->count('adjudication_case_id'));
    }
}
