<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\MerchantReviewDecision;
use App\Models\User;
use App\Services\Eligibility\MerchantCandidates;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * The BID review queue for Merchant Eligibility Matching: real candidates
 * with the AI's answer; Sam decides; every decision is a new log row;
 * approving never makes a merchant; judges look but can't decide; the
 * shared public judge account sees totals only.
 */
class MerchantReviewQueueTest extends TestCase
{
    use RefreshDatabase;

    private array $candidates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->candidates = MerchantCandidates::load();
        $path = sys_get_temp_dir().'/lg-assessments-'.uniqid().'.json';
        $answer = fn (string $class, float $confidence, bool $review) => [
            'prompt_version' => 'abcd1234', 'assessed_at' => '2026-10-15T12:00:00-04:00', 'eligible' => $class === 'independent',
            'classification' => $class, 'confidence' => $confidence, 'reasoning' => "AI reasoning: {$class}.",
            'needs_human_review' => $review, 'assessed_by' => 'claude-opus-5-5',
        ];
        file_put_contents($path, json_encode(['assessments' => [
            'MC-001' => $answer('outside_control', 0.9, false),
            'MC-002' => $answer('undetermined', 0.4, true),
            'MC-003' => $answer('independent', 0.99, false),
        ]]));
        config(['eligibility.assessments_path' => $path]);
    }

    private function judge(?ReviewerType $type): User
    {
        // A fixed name: a random one can contain a short candidate name ("Ala" in "Alaina").
        return User::factory()->role(Role::Judge)->create(['name' => 'Test Judge', 'reviewer_type' => $type]);
    }

    public function test_sam_sees_each_candidate_with_the_ai_answer_and_the_candidate_label(): void
    {
        $this->signIn(Role::BidAdmin);

        $this->get('/bid-admin/merchants')->assertOk()
            ->assertSee(config('eligibility.candidate_label'))
            ->assertSee($this->candidates['MC-001']['name'])
            ->assertSee('AI: Outside control')
            ->assertSee('90% (high)')
            ->assertSee('AI reasoning: undetermined.')
            ->assertSee('did not search the web')
            ->assertSee('Flagged for a person')
            ->assertSeeInOrder([$this->candidates['MC-002']['name'], $this->candidates['MC-001']['name']]) // flagged first
            ->assertSee('Approve')
            ->assertDontSee('my_note');
    }

    public function test_a_decision_is_logged_with_who_and_when_and_changes_add_rows(): void
    {
        $sam = $this->signIn(Role::BidAdmin);

        $this->post('/bid-admin/merchants/MC-001/decisions', ['decision' => 'needs_research', 'note' => 'Check the parent company.'])
            ->assertRedirect()->assertSessionHas('status');
        $this->travel(1)->minutes();
        $this->post('/bid-admin/merchants/MC-001/decisions', ['decision' => 'reject'])->assertRedirect();

        $log = MerchantReviewDecision::where('candidate_id', 'MC-001')->orderBy('id')->get();
        $this->assertSame(['needs_research', 'reject'], $log->pluck('decision')->all());
        $this->assertSame([$sam->id, $sam->id], $log->pluck('user_id')->all());
        $this->assertSame('outside_control', $log[0]->ai_classification);
        $this->assertSame('abcd1234', $log[0]->ai_prompt_version);

        $this->get('/bid-admin/merchants')->assertSee('Rejected')->assertSee('Needs research')->assertSee('by Sam Taylor')->assertSee('Check the parent company.');
    }

    public function test_approving_creates_no_merchant_redemption_or_ledger_entry(): void
    {
        $this->signIn(Role::BidAdmin);
        $counts = fn () => collect(['merchants', 'redemptions', 'credit_ledger_entries', 'merchant_payouts'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
        $before = $counts();

        $this->post('/bid-admin/merchants/MC-001/decisions', ['decision' => 'approve'])->assertRedirect();

        $this->assertSame($before, $counts());
        $this->assertSame(0, DB::table('merchants')->where('name', $this->candidates['MC-001']['name'])->count());
        $this->assertSame(1, MerchantReviewDecision::count());
    }

    public function test_bad_decisions_and_unassessed_candidates_are_refused(): void
    {
        $this->signIn(Role::BidAdmin);

        $this->post('/bid-admin/merchants/MC-001/decisions', ['decision' => 'maybe'])->assertSessionHasErrors('decision');
        $this->post('/bid-admin/merchants/MC-004/decisions', ['decision' => 'approve'])->assertNotFound();
        $this->assertSame(0, MerchantReviewDecision::count());
    }

    public function test_the_log_is_append_only(): void
    {
        $decision = MerchantReviewDecision::create(['candidate_id' => 'MC-001', 'decision' => 'approve', 'user_id' => User::factory()->role(Role::BidAdmin)->create()->id]);

        $this->expectException(LogicException::class);
        $decision->update(['decision' => 'reject']);
    }

    public function test_the_log_refuses_deletes(): void
    {
        $decision = MerchantReviewDecision::create(['candidate_id' => 'MC-001', 'decision' => 'approve', 'user_id' => User::factory()->role(Role::BidAdmin)->create()->id]);

        $this->expectException(LogicException::class);
        $decision->delete();
    }

    public function test_a_named_judge_sees_names_read_only_and_cannot_decide(): void
    {
        $this->actingAs($this->judge(ReviewerType::ContestJudge));

        $this->get('/bid-admin/merchants')->assertOk()
            ->assertSee($this->candidates['MC-001']['name'])
            ->assertSee('Read-only')
            ->assertDontSee('name="decision"', false);
        $this->post('/bid-admin/merchants/MC-001/decisions', ['decision' => 'approve'])->assertForbidden();
        $this->assertSame(0, MerchantReviewDecision::count());
    }

    public function test_the_public_judge_account_sees_totals_only(): void
    {
        foreach ([ReviewerType::Public, null] as $type) {
            $this->actingAs($this->judge($type));

            $html = $this->get('/bid-admin/merchants')->assertOk()
                ->assertSee('Business names are hidden')
                ->assertSee('Outside control: 1')
                ->getContent();

            foreach ($this->candidates as $id => $c) {
                $this->assertStringNotContainsString(e($c['name']), $html, "{$id} is shown to the public judge account");
            }
            $this->assertStringNotContainsString('AI reasoning', $html);
        }
    }

    public function test_an_ai_eligible_is_only_a_recommendation_and_stays_waiting(): void
    {
        $this->signIn(Role::BidAdmin);

        $this->get('/bid-admin/merchants')->assertOk()
            ->assertSee('AI recommends eligible — confirm before approving')
            ->assertSee('never approves a merchant on its own')
            ->assertSee('Approved · rejected · needs research')
            ->assertSeeInOrder(['Waiting for a decision', '3']);

        $this->assertSame(0, MerchantReviewDecision::count(), 'Opening the queue decided something.');
    }

    public function test_no_code_path_writes_a_decision_without_a_person(): void
    {
        // The model refuses a decision with no person, or with anyone but a BID or platform admin.
        foreach ([null, Role::Judge, Role::Employee, Role::EmployerAdmin, Role::Merchant] as $role) {
            $user = $role ? User::factory()->role($role)->create() : null;
            try {
                MerchantReviewDecision::create(['candidate_id' => 'MC-003', 'decision' => 'approve', 'user_id' => $user?->id]);
                $this->fail('A decision was saved without a BID or platform admin: '.($role?->value ?? 'nobody'));
            } catch (LogicException) {
                // expected
            }
        }
        $this->assertSame(0, MerchantReviewDecision::count());

        // In the app's own code, only the review queue's controller writes decisions,
        // and it does so only after the decide-merchant-eligibility gate.
        $writers = [];
        foreach (['app', 'database/seeders', 'routes', 'config'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)) as $file) {
                $code = file_get_contents($file->getPathname());
                if (preg_match('/MerchantReviewDecision::(create|forceCreate|insert|firstOrCreate|updateOrCreate|make|query)|new MerchantReviewDecision|merchant_review_decisions/', $code)
                    && ! str_contains($file->getPathname(), 'database/migrations')) {
                    $writers[] = str_replace(base_path().'/', '', $file->getPathname());
                }
            }
        }
        $this->assertSame(['app/Http/Controllers/BidAdmin/MerchantEligibilityController.php'], $writers);
        $controller = file_get_contents(app_path('Http/Controllers/BidAdmin/MerchantEligibilityController.php'));
        $this->assertLessThan(strpos($controller, 'MerchantReviewDecision::create'), strpos($controller, "Gate::authorize('decide-merchant-eligibility')"));
    }

    public function test_other_roles_cannot_open_or_decide(): void
    {
        $this->signIn(Role::Employee);

        $this->get('/bid-admin/merchants')->assertForbidden();
        $this->post('/bid-admin/merchants/MC-001/decisions', ['decision' => 'approve'])->assertForbidden();
        $this->assertSame(0, MerchantReviewDecision::count());
    }
}
