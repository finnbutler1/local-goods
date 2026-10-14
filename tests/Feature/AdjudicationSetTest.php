<?php

namespace Tests\Feature;

use App\Models\AdjudicationCase;
use App\Models\AdjudicationSet;
use App\Services\Adjudication\AdjudicationSetBuilder;
use App\Services\Fraud\FraudEvalReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * Building the blind adjudication set (ADR 0011) from the saved eval run in
 * docs/evals. Reads the eval files only and never changes them.
 */
class AdjudicationSetTest extends TestCase
{
    use RefreshDatabase;

    private AdjudicationSetBuilder $builder;

    /** @var list<array<string, mixed>> */
    private array $results;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = app(AdjudicationSetBuilder::class);
        $this->results = app(FraudEvalReport::class)->latest()['results'];
    }

    public function test_a_new_draft_has_every_disputed_case_from_every_split_and_as_many_controls(): void
    {
        $disputed = collect($this->results)->reject(fn ($r) => $r['agree'])->pluck('case_id')->sort()->values();

        $set = $this->builder->newDraft();

        $this->assertTrue($set->isDraft());
        $this->assertSame(1, $set->version);
        $this->assertEquals($disputed, $set->cases()->where('kind', 'disputed')->pluck('case_id')->sort()->values());
        $this->assertCount(4, $set->cases()->where('kind', 'disputed')->distinct()->pluck('split'), 'Disputed cases come from all four splits.');

        $controls = $set->cases()->where('kind', 'control')->get();
        $this->assertCount($disputed->count(), $controls);
        foreach ($controls as $control) {
            $this->assertSame($control->my_label, $control->ai_label, "{$control->case_id} is a control, so both agreed.");
        }
    }

    public function test_cases_keep_only_the_facts_adjudicators_see(): void
    {
        $case = $this->builder->newDraft()->cases()->firstOrFail();

        $this->assertSame(array_keys(AdjudicationCase::FACTS), array_keys($case->facts));
        $this->assertArrayNotHasKey('notes', $case->facts);
        $this->assertArrayNotHasKey('my_label', $case->facts);
    }

    public function test_the_admin_can_add_and_remove_cases_in_a_draft(): void
    {
        $set = $this->builder->newDraft();
        $outside = collect($this->results)->pluck('case_id')->diff($set->cases()->pluck('case_id'))->first();

        $added = $this->builder->add($set, $outside);
        $this->assertSame('control', $added->kind);

        $this->builder->remove($added);
        $this->assertFalse($set->cases()->where('case_id', $outside)->exists());
    }

    public function test_adding_an_unknown_or_duplicate_case_is_refused(): void
    {
        $set = $this->builder->newDraft();

        $this->expectException(InvalidArgumentException::class);
        $this->builder->add($set, $set->cases()->value('case_id'));
    }

    public function test_publishing_locks_the_set(): void
    {
        $set = $this->builder->newDraft();
        $this->builder->publish($set);

        $this->assertTrue($set->fresh()->isOpen());
        $this->assertSame($set->id, AdjudicationSet::current()->id);

        $this->expectException(LogicException::class);
        $set->cases()->firstOrFail()->delete();
    }

    public function test_a_published_case_cannot_be_edited_either(): void
    {
        $set = $this->builder->newDraft();
        $this->builder->publish($set);

        $this->expectException(LogicException::class);
        $set->cases()->firstOrFail()->update(['my_label' => 'normal']);
    }

    public function test_a_set_needs_disputed_and_control_cases_to_publish(): void
    {
        $set = $this->builder->newDraft();
        $set->cases()->where('kind', 'control')->get()->each->delete();

        $this->expectException(InvalidArgumentException::class);
        $this->builder->publish($set);
    }

    public function test_a_new_version_starts_from_the_current_set_and_closes_it_when_published(): void
    {
        $first = $this->builder->newDraft();
        $this->builder->publish($first);

        $second = $this->builder->newDraft();
        $this->assertSame(2, $second->version);
        $this->assertEquals($first->cases()->pluck('case_id')->sort()->values(), $second->cases()->pluck('case_id')->sort()->values());

        $second->cases()->firstOrFail()->delete();
        $this->assertSame($first->cases()->count() - 1, $second->cases()->count(), 'The old version is untouched.');

        $this->builder->publish($second);
        $this->assertTrue($first->fresh()->isClosed());
        $this->assertSame($second->id, AdjudicationSet::current()->id);
    }

    public function test_building_a_set_never_changes_the_eval_files(): void
    {
        $paths = [config('fraud.eval_cases_path'), config('fraud.eval_latest_path'), config('fraud.eval_lock_path')];
        $before = array_map('md5_file', $paths);

        $set = $this->builder->newDraft();
        $this->builder->publish($set);
        $this->builder->close($set);

        $this->assertSame($before, array_map('md5_file', $paths));
    }
}
