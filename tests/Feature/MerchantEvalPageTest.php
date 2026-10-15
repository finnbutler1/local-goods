<?php

namespace Tests\Feature;

use App\Enums\ReviewerType;
use App\Enums\Role;
use App\Models\User;
use App\Services\Eligibility\EligibilityAssessment;
use App\Services\Eligibility\MerchantCandidates;
use App\Services\Eligibility\MerchantEvalRunner;
use App\Services\Eligibility\MerchantLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /eval/merchants: totals for everyone; real business names, Finn's notes,
 * evidence links and per-case tables only for named reviewers and admins.
 * Logged-out visitors and the shared public judge account count as public.
 */
class MerchantEvalPageTest extends TestCase
{
    use RefreshDatabase;

    private array $labels;

    protected function setUp(): void
    {
        parent::setUp();

        // Results built from the real labeled candidates, with every AI answer "undetermined",
        // so every case is a disagreement and every name would be listed.
        $this->labels = MerchantLabels::load();
        $candidates = MerchantCandidates::load();
        $answer = new EligibilityAssessment(false, 'undetermined', 0.5, 'The AI does not recognize this business.', true, 'claude-opus-5-5');
        $results = [];
        foreach ($this->labels as $id => $label) {
            $results[$label['split']][] = MerchantEvalRunner::score($candidates[$id] + ['business_activity' => 'Restaurant'] + $label, $answer);
        }
        $run = fn (string $split) => [
            'run_at' => '2026-10-15T12:00:00-04:00', 'model' => 'claude-opus-5-5', 'prompt_version' => 'abcd1234', 'split' => $split,
            'case_count' => count($results[$split]), 'api_calls' => 0, 'errors' => [], 'note' => null,
            'summary' => MerchantEvalRunner::summarize($results[$split]), 'results' => $results[$split],
        ];

        $path = sys_get_temp_dir().'/lg-merchant-latest-'.uniqid().'.json';
        file_put_contents($path, json_encode([
            'updated_at' => '2026-10-15T12:00:00-04:00', 'current_prompt_version' => 'abcd1234',
            'splits' => ['dev' => $run('dev'), 'holdout' => null], 'holdout_seen_by' => [],
            'history' => [['name' => 'x', 'run_at' => '2026-10-15T12:00:00-04:00', 'model' => 'claude-opus-5-5', 'prompt_version' => 'abcd1234',
                'split' => 'dev', 'note' => null, 'cases' => 40, 'agreement_pct' => 75.0,
                'recall' => $run('dev')['summary']['recall'], 'precision' => $run('dev')['summary']['precision'], 'file' => 'x.md']],
        ]));
        config(['eligibility.eval_latest_path' => $path]);
    }

    private function assertNoCaseDetails(string $html, string $who): void
    {
        foreach (MerchantCandidates::load() as $id => $c) {
            $this->assertDoesNotMatchRegularExpression('/\b'.preg_quote(e($c['name']), '/').'\b/', $html, "{$who}: {$id} {$c['name']} is shown");
        }
        foreach ($this->labels as $id => $label) {
            $this->assertStringNotContainsString(e($label['evidence_url']), $html, "{$who}: {$id} evidence link is shown");
            if (strlen($label['my_note']) >= 30) {
                $this->assertStringNotContainsString(e($label['my_note']), $html, "{$who}: {$id} note is shown");
            }
        }
        $this->assertStringNotContainsString('does not recognize this business', $html, "{$who}: per-case AI reasoning is shown");
        $this->assertStringNotContainsString('MC-0', $html, "{$who}: candidate ids are shown");
    }

    public function test_logged_out_visitors_see_totals_and_no_business_names(): void
    {
        $html = $this->get('/eval/merchants')->assertOk()
            ->assertSee('Eligible yes/no agreement')
            ->assertSee('Baseline: always answer “not eligible”')
            ->assertSee('Independent businesses the AI found')
            ->assertSee('AI “eligible” calls that were right')
            ->assertSee('Totals only on this page')
            ->assertSee(config('eligibility.candidate_label'))
            ->assertSee('did not search the web')
            ->getContent();

        $this->assertNoCaseDetails($html, 'logged out');
    }

    public function test_the_public_judge_account_counts_as_public(): void
    {
        foreach ([ReviewerType::Public, null] as $type) {
            $this->actingAs(User::factory()->role(Role::Judge)->create(['reviewer_type' => $type]));

            $this->assertNoCaseDetails($this->get('/eval/merchants')->assertOk()->assertSee('Totals only')->getContent(), $type?->value ?? 'no type');
        }
    }

    public function test_named_reviewers_and_admins_see_every_disagreement(): void
    {
        $first = $this->labels[array_key_first($this->labels)];
        $users = [
            User::factory()->role(Role::Judge)->create(['reviewer_type' => ReviewerType::ContestJudge]),
            User::factory()->role(Role::Judge)->create(['reviewer_type' => ReviewerType::Friend]),
            User::factory()->role(Role::BidAdmin)->create(),
            User::factory()->role(Role::PlatformAdmin)->create(),
        ];

        foreach ($users as $user) {
            $this->actingAs($user);
            $this->get('/eval/merchants')->assertOk()
                ->assertSee($first['merchant_name'])
                ->assertSee($first['evidence_url'])
                ->assertSee('does not recognize this business')
                ->assertSee('Disagreements (40)')
                ->assertSee(config('eligibility.candidate_label'))
                ->assertDontSee('Totals only');
        }
    }

    public function test_an_empty_state_before_the_first_run(): void
    {
        config(['eligibility.eval_latest_path' => '/nonexistent/merchant-eval-latest.json']);

        $this->get('/eval/merchants')->assertOk()->assertSee('No results yet');
    }

    public function test_the_fraud_eval_page_links_here(): void
    {
        $this->get('/eval')->assertOk()->assertSee(route('eval.merchants'));
    }
}
